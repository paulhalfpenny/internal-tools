<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OneOffHistoricalHarvestTime extends Command
{
    protected $signature = 'app:one-off-historical-harvest-time
        {path : Harvest detailed-time CSV export}
        {--expected-sha256= : Required source file SHA-256 for commit runs}
        {--commit : Insert the approved rows instead of dry-running}';

    protected $description = 'One-off guarded import of approved Harvest history through June 2026.';

    private const CUTOFF = '2026-06-30';

    private const IMPORT_ENTITY_TYPE = 'historical_harvest_time_entry';

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'Date',
        'Client',
        'Project',
        'Project Code',
        'Task',
        'Notes',
        'Hours',
        'Billable?',
        'Invoiced?',
        'Approved?',
        'First Name',
        'Last Name',
        'Employee Id',
        'Roles',
        'Teams',
        'Employee?',
        'Billable Rate',
        'Billable Amount',
        'Cost Rate',
        'Cost Amount',
        'Currency',
        'External Reference URL',
    ];

    /** @var array<string, int> */
    private array $projectIds = [];

    /** @var array<string, int> */
    private array $userIds = [];

    /** @var array<string, int> */
    private array $taskIds = [];

    public function __construct(private readonly HistoricalHarvestTimeImportManifest $manifest)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $commit = (bool) $this->option('commit');
        $expectedHash = strtolower(trim((string) $this->option('expected-sha256')));

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or unreadable: {$path}");

            return self::FAILURE;
        }

        if ($commit && $expectedHash === '') {
            $this->error('--expected-sha256 is required with --commit.');

            return self::FAILURE;
        }

        try {
            [$source, $actualHash] = $this->sourceSnapshot($path);
            $approvedHash = $this->approvedSourceSha256();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($approvedHash !== null && ! hash_equals($approvedHash, $actualHash)) {
            fclose($source);
            $this->error("Source SHA-256 {$actualHash} does not match the approved source SHA-256 {$approvedHash}.");

            return self::FAILURE;
        }

        if ($expectedHash !== '' && (! preg_match('/^[a-f0-9]{64}$/', $expectedHash) || ! hash_equals($expectedHash, $actualHash))) {
            fclose($source);
            $this->error("Source SHA-256 mismatch. Expected {$expectedHash}; got {$actualHash}.");

            return self::FAILURE;
        }

        try {
            $rows = $this->selectedRows($source);
            $this->assertLedgerMatchesSource($rows, $actualHash);
            $this->resolveReferences($rows);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            fclose($source);
        }

        try {
            $ledgerTargets = $this->ledgerTargets(array_column($rows, 'source_id'));
            $this->assertLedgerEntriesAreUnchanged($rows, $ledgerTargets);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $unimportedRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! isset($ledgerTargets[$row['source_id']])
        ));

        try {
            $allConflicts = $this->conflicts($unimportedRows, array_values($ledgerTargets));
            $approvedSkips = $this->validateApprovedSkips($rows, $ledgerTargets, $allConflicts);
            $rowsToInsert = array_values(array_filter(
                $unimportedRows,
                fn (array $row): bool => ! isset($approvedSkips[$row['source_id']])
            ));
            $conflicts = $this->conflicts($rowsToInsert, array_values($ledgerTargets));
            $report = $this->reportRows($rows, $ledgerTargets, $conflicts, $approvedSkips);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report, $actualHash);
        $this->printApprovedSkips(array_values($approvedSkips));
        $this->printConflicts($conflicts);

        $blocked = array_filter($report, fn (array $row): bool => in_array($row['status'], ['BLOCKED', 'CONFLICT'], true));
        if ($blocked !== []) {
            $this->error('Import is blocked until every project matches its approved row count, amount, and conflict state.');

            return self::FAILURE;
        }

        if (! $commit) {
            $this->warn('DRY RUN only. No database rows were written.');
            $this->info('Selected '.number_format(count($rows)).' row(s).');
            $this->info('Already imported: '.number_format(count($ledgerTargets)));
            $this->info('Approved skips: '.number_format(count($approvedSkips)));
            $this->info('Would insert: '.number_format(count($rowsToInsert)));

            return self::SUCCESS;
        }

        try {
            $inserted = $this->insertRows(
                $rowsToInsert,
                $actualHash,
                $rows,
                $unimportedRows,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Imported: '.number_format($inserted));
        $this->info('Already imported: '.number_format(count($ledgerTargets)));
        $this->info('Approved skips: '.number_format(count($approvedSkips)));

        return self::SUCCESS;
    }

    private function approvedSourceSha256(): ?string
    {
        $hash = $this->manifest->expectedSourceSha256();
        if ($hash !== null && ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Historical Harvest approved source SHA-256 is invalid.');
        }

        return $hash;
    }

    /** @return array{0: resource, 1: string} */
    private function sourceSnapshot(string $path): array
    {
        $source = fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException("Unable to open source file: {$path}");
        }

        $snapshot = tmpfile();
        if ($snapshot === false) {
            fclose($source);
            throw new RuntimeException('Unable to create a temporary snapshot of the source file.');
        }

        $copied = stream_copy_to_stream($source, $snapshot);
        fclose($source);
        if ($copied === false || ! rewind($snapshot)) {
            fclose($snapshot);
            throw new RuntimeException('Unable to snapshot the source file.');
        }

        $hash = hash_init('sha256');
        hash_update_stream($hash, $snapshot);
        $actualHash = hash_final($hash);
        if (! rewind($snapshot)) {
            fclose($snapshot);
            throw new RuntimeException('Unable to read the source file snapshot.');
        }

        return [$snapshot, $actualHash];
    }

    /**
     * @param  resource  $handle
     * @return list<array{
     *     source_id: string,
     *     source_row: int,
     *     target_code: string,
     *     source_code: string,
     *     source_project: string,
     *     user_name: string,
     *     task_name: string,
     *     spent_on: string,
     *     hours: float,
     *     notes: string|null,
     *     is_billable: bool,
     *     billable_rate: float,
     *     billable_amount: float,
     *     external_reference: string|null,
     *     project_id?: int,
     *     user_id?: int,
     *     task_id?: int
     * }>
     */
    private function selectedRows($handle): array
    {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            throw new RuntimeException('The source CSV is empty.');
        }

        $this->assertHeaders($header);

        /** @var array<string, int> $headerIndexes */
        $headerIndexes = array_flip($header);
        $mappings = $this->mappings();
        $selected = [];
        $occurrences = [];
        $sourceRow = 1;

        while (($csvRow = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $sourceRow++;

            if (count($csvRow) !== count($header)) {
                throw new RuntimeException("CSV row {$sourceRow} has ".count($csvRow).' columns; expected '.count($header).'.');
            }

            $values = [];
            foreach ($headerIndexes as $name => $index) {
                $values[$name] = trim($csvRow[$index]);
            }

            $mapping = $this->mappingFor($values, $mappings);
            if ($mapping === null) {
                continue;
            }

            $date = $values['Date'];
            $this->assertValidDate($date, $sourceRow);
            if (($mapping['from'] !== null && $date < $mapping['from']) || $date > self::CUTOFF) {
                continue;
            }

            $numbers = $this->validateSelectedRow($values, $sourceRow);

            $fingerprintPayload = [
                'target_code' => $mapping['target_code'],
                'row' => array_map(fn (string $headerName): string => $values[$headerName], self::REQUIRED_HEADERS),
            ];
            $json = json_encode($fingerprintPayload, JSON_THROW_ON_ERROR);
            $fingerprint = hash('sha256', $json);
            $occurrence = ($occurrences[$fingerprint] ?? 0) + 1;
            $occurrences[$fingerprint] = $occurrence;

            $billable = $values['Billable?'] === 'Yes';
            $selected[] = [
                'source_id' => "historical-time:v1:{$fingerprint}:{$occurrence}",
                'source_row' => $sourceRow,
                'target_code' => $mapping['target_code'],
                'source_code' => $mapping['source_code'],
                'source_project' => $mapping['source_project'],
                'user_name' => $this->userName($values['First Name'], $values['Last Name']),
                'task_name' => $this->taskName($values['Task']),
                'spent_on' => $date,
                'hours' => $numbers['hours'],
                'notes' => $values['Notes'] !== '' ? $values['Notes'] : null,
                'is_billable' => $billable,
                'billable_rate' => $billable ? $numbers['billable_rate'] : 0.0,
                'billable_amount' => $billable ? $numbers['billable_amount'] : 0.0,
                'external_reference' => $values['External Reference URL'] !== '' ? $values['External Reference URL'] : null,
            ];
        }

        return $selected;
    }

    /** @param list<string> $header */
    private function assertHeaders(array $header): void
    {
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $header));
        if ($missing !== []) {
            throw new RuntimeException('Missing required CSV header(s): '.implode(', ', $missing).'.');
        }

        if (count($header) !== count(array_unique($header))) {
            throw new RuntimeException('The source CSV contains duplicate headers.');
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<array{target_code: string, source_client: string, source_code: string, source_project: string, from: string|null, expected_rows: int, table_amount: int}>  $mappings
     * @return array{target_code: string, source_client: string, source_code: string, source_project: string, from: string|null, expected_rows: int, table_amount: int}|null
     */
    private function mappingFor(array $values, array $mappings): ?array
    {
        foreach ($mappings as $mapping) {
            if ($values['Client'] === $mapping['source_client']
                && $values['Project Code'] === $mapping['source_code']
                && $values['Project'] === $mapping['source_project']) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * @return list<array{target_code: string, source_client: string, source_code: string, source_project: string, from: string|null, expected_rows: int, table_amount: int}>
     */
    private function mappings(): array
    {
        $configured = $this->manifest->mappings();
        if (! is_array($configured) || $configured === []) {
            throw new RuntimeException('Historical Harvest import mappings are missing.');
        }

        $mappings = [];
        foreach ($configured as $index => $mapping) {
            if (! is_array($mapping)
                || ! isset($mapping['target_code'], $mapping['source_client'], $mapping['source_code'], $mapping['source_project'], $mapping['expected_rows'], $mapping['table_amount'])
                || ! is_string($mapping['target_code'])
                || ! is_string($mapping['source_client'])
                || ! is_string($mapping['source_code'])
                || ! is_string($mapping['source_project'])
                || ! is_int($mapping['expected_rows'])
                || ! is_int($mapping['table_amount'])
                || (($mapping['from'] ?? null) !== null && ! is_string($mapping['from']))) {
                throw new RuntimeException("Historical Harvest import mapping {$index} is invalid.");
            }

            $from = $mapping['from'] ?? null;
            if ($from !== null) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
                if ($date === false || $date->format('Y-m-d') !== $from) {
                    throw new RuntimeException("Historical Harvest import mapping {$index} has an invalid from date.");
                }
            }

            if ($mapping['target_code'] === ''
                || $mapping['source_client'] === ''
                || $mapping['source_code'] === ''
                || $mapping['source_project'] === ''
                || $mapping['expected_rows'] < 1
                || $mapping['table_amount'] < 0) {
                throw new RuntimeException("Historical Harvest import mapping {$index} contains an invalid empty or numeric value.");
            }

            $mappings[] = [
                'target_code' => $mapping['target_code'],
                'source_client' => $mapping['source_client'],
                'source_code' => $mapping['source_code'],
                'source_project' => $mapping['source_project'],
                'from' => $from,
                'expected_rows' => $mapping['expected_rows'],
                'table_amount' => $mapping['table_amount'],
            ];
        }

        return $mappings;
    }

    /** @return array<string, float> */
    private function approvedAmountExceptions(): array
    {
        $configured = $this->manifest->approvedAmountExceptions();
        $mappingCodes = array_column($this->mappings(), 'target_code');
        $exceptions = [];

        foreach ($configured as $index => $exception) {
            if (! is_array($exception)
                || ! isset($exception['target_code'], $exception['csv_amount'])
                || ! is_string($exception['target_code'])
                || (! is_int($exception['csv_amount']) && ! is_float($exception['csv_amount']))
                || ! is_finite((float) $exception['csv_amount'])
                || (float) $exception['csv_amount'] < 0
                || ! in_array($exception['target_code'], $mappingCodes, true)
                || isset($exceptions[$exception['target_code']])) {
                throw new RuntimeException("Historical Harvest amount exception {$index} is invalid.");
            }

            $exceptions[$exception['target_code']] = (float) $exception['csv_amount'];
        }

        return $exceptions;
    }

    /**
     * @return list<array{
     *     source_id: string,
     *     target_code: string,
     *     spent_on: string,
     *     user_name: string,
     *     task_name: string,
     *     source_hours: float,
     *     source_amount: float,
     *     existing_rows: int,
     *     existing_hours: float,
     *     existing_amount: float
     * }>
     */
    private function approvedSkips(): array
    {
        $configured = $this->manifest->approvedSkips();
        $mappingCodes = array_column($this->mappings(), 'target_code');
        $skips = [];
        $seen = [];

        foreach ($configured as $index => $skip) {
            if (! is_array($skip)
                || ! isset(
                    $skip['source_id'],
                    $skip['target_code'],
                    $skip['spent_on'],
                    $skip['user_name'],
                    $skip['task_name'],
                    $skip['source_hours'],
                    $skip['source_amount'],
                    $skip['existing_rows'],
                    $skip['existing_hours'],
                    $skip['existing_amount'],
                )
                || ! is_string($skip['source_id'])
                || ! preg_match('/^historical-time:v1:[a-f0-9]{64}:[1-9]\d*$/', $skip['source_id'])
                || ! is_string($skip['target_code'])
                || ! in_array($skip['target_code'], $mappingCodes, true)
                || ! is_string($skip['spent_on'])
                || ! is_string($skip['user_name'])
                || $skip['user_name'] === ''
                || ! is_string($skip['task_name'])
                || $skip['task_name'] === ''
                || (! is_int($skip['source_hours']) && ! is_float($skip['source_hours']))
                || (! is_int($skip['source_amount']) && ! is_float($skip['source_amount']))
                || ! is_int($skip['existing_rows'])
                || $skip['existing_rows'] < 1
                || (! is_int($skip['existing_hours']) && ! is_float($skip['existing_hours']))
                || (! is_int($skip['existing_amount']) && ! is_float($skip['existing_amount']))
                || isset($seen[$skip['source_id']])) {
                throw new RuntimeException("Historical Harvest approved skip {$index} is invalid.");
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $skip['spent_on']);
            $numbers = [
                (float) $skip['source_hours'],
                (float) $skip['source_amount'],
                (float) $skip['existing_hours'],
                (float) $skip['existing_amount'],
            ];
            $hasInvalidNumber = false;
            foreach ($numbers as $number) {
                if (! is_finite($number) || $number < 0) {
                    $hasInvalidNumber = true;
                    break;
                }
            }

            if ($date === false
                || $date->format('Y-m-d') !== $skip['spent_on']
                || $hasInvalidNumber) {
                throw new RuntimeException("Historical Harvest approved skip {$index} is invalid.");
            }

            $seen[$skip['source_id']] = true;
            $skips[] = [
                'source_id' => $skip['source_id'],
                'target_code' => $skip['target_code'],
                'spent_on' => $skip['spent_on'],
                'user_name' => $skip['user_name'],
                'task_name' => $skip['task_name'],
                'source_hours' => (float) $skip['source_hours'],
                'source_amount' => (float) $skip['source_amount'],
                'existing_rows' => $skip['existing_rows'],
                'existing_hours' => (float) $skip['existing_hours'],
                'existing_amount' => (float) $skip['existing_amount'],
            ];
        }

        return $skips;
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @param  array<string, int>  $ledgerTargets
     * @param  list<array<string, mixed>>  $conflicts
     * @return array<string, array<string, mixed>>
     */
    private function validateApprovedSkips(array $sourceRows, array $ledgerTargets, array $conflicts): array
    {
        $sourceIds = array_fill_keys(array_column($sourceRows, 'source_id'), true);
        $conflictsBySourceId = [];
        foreach ($conflicts as $conflict) {
            foreach ($conflict['source_ids'] as $sourceId) {
                $conflictsBySourceId[$sourceId] = $conflict;
            }
        }

        $approved = [];
        foreach ($this->approvedSkips() as $skip) {
            $sourceId = $skip['source_id'];
            $conflict = $conflictsBySourceId[$sourceId] ?? null;
            if (! isset($sourceIds[$sourceId])
                || isset($ledgerTargets[$sourceId])
                || $conflict === null
                || $conflict['source_ids'] !== [$sourceId]
                || $conflict['target_code'] !== $skip['target_code']
                || $conflict['spent_on'] !== $skip['spent_on']
                || $conflict['user_name'] !== $skip['user_name']
                || $conflict['task_name'] !== $skip['task_name']
                || $conflict['source_rows'] !== 1
                || abs($conflict['source_hours'] - $skip['source_hours']) >= 0.005
                || abs($conflict['source_amount'] - $skip['source_amount']) >= 0.005
                || $conflict['existing_rows'] !== $skip['existing_rows']
                || abs($conflict['existing_hours'] - $skip['existing_hours']) >= 0.005
                || abs($conflict['existing_amount'] - $skip['existing_amount']) >= 0.005) {
                throw new RuntimeException("Historical Harvest approved skip no longer matches current source and existing entries: {$sourceId}.");
            }

            $approved[$sourceId] = $conflict;
        }

        return $approved;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{hours: float, billable_rate: float, billable_amount: float}
     */
    private function validateSelectedRow(array $values, int $sourceRow): array
    {
        $hours = $this->numericValue($values['Hours'], 'Hours', $sourceRow);
        if ($hours <= 0 || $hours > 24) {
            throw new RuntimeException("CSV row {$sourceRow} has invalid Hours; expected a number greater than 0 and no more than 24.");
        }

        if (! in_array($values['Billable?'], ['Yes', 'No'], true)) {
            throw new RuntimeException("CSV row {$sourceRow} has invalid Billable?; expected Yes or No.");
        }

        if ($values['Invoiced?'] !== 'No' || $values['Approved?'] !== 'No') {
            throw new RuntimeException("CSV row {$sourceRow} is invoiced or approved; this one-off import only accepts No/No rows.");
        }

        $billableRate = $this->numericValue($values['Billable Rate'], 'Billable Rate', $sourceRow);
        $billableAmount = $this->numericValue($values['Billable Amount'], 'Billable Amount', $sourceRow);
        $this->numericValue($values['Cost Rate'], 'Cost Rate', $sourceRow);
        $this->numericValue($values['Cost Amount'], 'Cost Amount', $sourceRow);

        if ($values['Billable?'] === 'No' && abs($billableAmount) > 0.005) {
            throw new RuntimeException("CSV row {$sourceRow} is non-billable but has a non-zero Billable Amount.");
        }

        if ($this->userName($values['First Name'], $values['Last Name']) === '' || trim($values['Task']) === '') {
            throw new RuntimeException("CSV row {$sourceRow} is missing its user or task name.");
        }

        return [
            'hours' => $hours,
            'billable_rate' => $billableRate,
            'billable_amount' => $billableAmount,
        ];
    }

    private function numericValue(string $value, string $field, int $sourceRow): float
    {
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/', $value)) {
            throw new RuntimeException("CSV row {$sourceRow} has an invalid {$field}.");
        }

        $number = (float) str_replace(',', '', $value);
        if (! is_finite($number) || $number < 0) {
            throw new RuntimeException("CSV row {$sourceRow} has an invalid {$field}.");
        }

        return $number;
    }

    private function assertValidDate(string $value, int $sourceRow): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException("CSV row {$sourceRow} has an invalid Date value.");
        }
    }

    private function userName(string $firstName, string $lastName): string
    {
        $name = trim($firstName.' '.$lastName);

        return strcasecmp($name, 'David Page') === 0 ? 'Dave Page' : $name;
    }

    private function taskName(string $name): string
    {
        $name = trim($name);

        return strcasecmp($name, 'Design') === 0 ? 'Design & UX' : $name;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function resolveReferences(array &$rows): void
    {
        foreach ($rows as &$row) {
            $row['project_id'] = $this->projectId((string) $row['target_code']);
            $row['user_id'] = $this->userId((string) $row['user_name']);
            $row['task_id'] = $this->taskId((string) $row['task_name']);
        }
        unset($row);
    }

    private function projectId(string $code): int
    {
        if (isset($this->projectIds[$code])) {
            return $this->projectIds[$code];
        }

        $projects = Project::query()->where('code', $code)->get();
        if ($projects->count() !== 1) {
            throw new RuntimeException("Expected exactly one Internal Tools project with code {$code}; found {$projects->count()}.");
        }

        return $this->projectIds[$code] = (int) $projects->firstOrFail()->id;
    }

    private function userId(string $name): int
    {
        $key = mb_strtolower($name);
        if (isset($this->userIds[$key])) {
            return $this->userIds[$key];
        }

        $users = User::query()->whereRaw('LOWER(name) = ?', [$key])->get();
        if ($users->count() !== 1) {
            throw new RuntimeException("Expected exactly one Internal Tools user named {$name}; found {$users->count()}.");
        }

        return $this->userIds[$key] = (int) $users->firstOrFail()->id;
    }

    private function taskId(string $name): int
    {
        $key = mb_strtolower($name);
        if (isset($this->taskIds[$key])) {
            return $this->taskIds[$key];
        }

        $tasks = Task::query()->whereRaw('LOWER(name) = ?', [$key])->get();
        if ($tasks->count() !== 1) {
            throw new RuntimeException("Expected exactly one Internal Tools task named {$name}; found {$tasks->count()}.");
        }

        return $this->taskIds[$key] = (int) $tasks->firstOrFail()->id;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $ledgerTargets
     * @param  list<array{target_code: string, spent_on: string, user_name: string, task_name: string, source_ids: list<string>, source_rows: int, source_hours: float, source_amount: float, existing_rows: int, existing_hours: float, existing_amount: float}>  $conflicts
     * @param  array<string, array<string, mixed>>  $approvedSkips
     * @return list<array{target_code: string, source: string, rows: int, expected_rows: int, hours: float, amount: float, table_amount: int, already_imported: int, skipped: int, to_insert: int, conflicts: int, status: string}>
     */
    private function reportRows(array $rows, array $ledgerTargets, array $conflicts, array $approvedSkips): array
    {
        $report = [];
        $amountExceptions = $this->approvedAmountExceptions();

        foreach ($this->mappings() as $mapping) {
            $matching = array_values(array_filter(
                $rows,
                fn (array $row): bool => $row['target_code'] === $mapping['target_code']
            ));
            $amount = array_sum(array_map(fn (array $row): float => (float) $row['billable_amount'], $matching));
            $hours = array_sum(array_map(fn (array $row): float => (float) $row['hours'], $matching));
            $countMatches = count($matching) === $mapping['expected_rows'];
            $amountMatches = (int) round($amount, 0, PHP_ROUND_HALF_UP) === $mapping['table_amount'];
            $hasAmountException = isset($amountExceptions[$mapping['target_code']]);
            $amountApproved = $hasAmountException
                && abs(round($amount, 2) - $amountExceptions[$mapping['target_code']]) < 0.005;
            $amountAccepted = $hasAmountException ? $amountApproved : $amountMatches;
            $alreadyImported = count(array_filter(
                $matching,
                fn (array $row): bool => isset($ledgerTargets[$row['source_id']])
            ));
            $skipped = count(array_filter(
                $matching,
                fn (array $row): bool => isset($approvedSkips[$row['source_id']])
            ));
            $conflictCount = count(array_filter(
                $conflicts,
                fn (array $conflict): bool => $conflict['target_code'] === $mapping['target_code']
            ));

            $report[] = [
                'target_code' => $mapping['target_code'],
                'source' => $mapping['source_code'].' / '.$mapping['source_project'],
                'rows' => count($matching),
                'expected_rows' => $mapping['expected_rows'],
                'hours' => round($hours, 2),
                'amount' => round($amount, 2),
                'table_amount' => $mapping['table_amount'],
                'already_imported' => $alreadyImported,
                'skipped' => $skipped,
                'to_insert' => count($matching) - $alreadyImported - $skipped,
                'conflicts' => $conflictCount,
                'status' => ! $countMatches || ! $amountAccepted
                    ? 'BLOCKED'
                    : ($conflictCount > 0
                        ? 'CONFLICT'
                        : ($hasAmountException || $skipped > 0 ? 'APPROVED' : 'OK')),
            ];
        }

        return $report;
    }

    /** @param list<array<string, mixed>> $report */
    private function printReport(array $report, string $hash): void
    {
        $this->line("Source SHA-256: {$hash}");
        $this->line('Cutoff: '.self::CUTOFF);
        $this->table(
            ['Target', 'Source', 'Rows', 'Expected', 'Hours', 'CSV amount', 'Table amount', 'Ledger', 'Skip', 'Insert', 'Conflicts', 'Status'],
            array_map(fn (array $row): array => [
                $row['target_code'],
                $row['source'],
                number_format((int) $row['rows']),
                number_format((int) $row['expected_rows']),
                number_format((float) $row['hours'], 2),
                '£'.number_format((float) $row['amount'], 2),
                '£'.number_format((int) $row['table_amount']),
                number_format((int) $row['already_imported']),
                number_format((int) $row['skipped']),
                number_format((int) $row['to_insert']),
                number_format((int) $row['conflicts']),
                $row['status'],
            ], $report)
        );
    }

    /** @param list<array<string, mixed>> $sourceRows */
    private function assertLedgerMatchesSource(array $sourceRows, string $sourceHash): void
    {
        $ledgerHash = null;
        $expectedRows = [];
        foreach ($sourceRows as $row) {
            $expectedRows[(string) $row['source_id']] = [
                'source_row' => (int) $row['source_row'],
                'target_code' => (string) $row['target_code'],
            ];
        }

        $seen = [];
        $entries = DB::table('harvest_import_log')
            ->where('entity_type', self::IMPORT_ENTITY_TYPE)
            ->orderBy('id')
            ->cursor();

        foreach ($entries as $entry) {
            $metadata = json_decode((string) $entry->notes, true);
            if (! is_array($metadata)
                || ! isset($metadata['source_sha256'], $metadata['source_row'], $metadata['target_code'])
                || ! is_string($metadata['source_sha256'])
                || ! preg_match('/^[a-f0-9]{64}$/', $metadata['source_sha256'])
                || ! is_int($metadata['source_row'])
                || $metadata['source_row'] < 2
                || ! is_string($metadata['target_code'])
                || $metadata['target_code'] === '') {
                throw new RuntimeException("Import ledger record {$entry->source_harvest_id} has invalid metadata.");
            }

            if ($ledgerHash !== null && ! hash_equals($ledgerHash, $metadata['source_sha256'])) {
                throw new RuntimeException('Historical Harvest import ledger contains more than one source SHA-256.');
            }

            $ledgerHash = $metadata['source_sha256'];

            if (! hash_equals($sourceHash, $metadata['source_sha256'])) {
                throw new RuntimeException("Historical Harvest import ledger belongs to source SHA-256 {$metadata['source_sha256']}; current source is {$sourceHash}.");
            }

            $sourceId = (string) $entry->source_harvest_id;
            $expected = $expectedRows[$sourceId] ?? null;
            if ($expected === null
                || $metadata['source_row'] !== $expected['source_row']
                || $metadata['target_code'] !== $expected['target_code']) {
                throw new RuntimeException("Import ledger record {$sourceId} does not match the current source.");
            }

            if (isset($seen[$sourceId])) {
                throw new RuntimeException("Import ledger contains duplicate records for {$sourceId}.");
            }

            if ($entry->target_id === null) {
                throw new RuntimeException("Import ledger record {$sourceId} has no target time entry.");
            }

            $seen[$sourceId] = true;
        }
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, int>
     */
    private function ledgerTargets(array $sourceIds): array
    {
        $targets = [];

        foreach (array_chunk($sourceIds, 500) as $chunk) {
            $entries = DB::table('harvest_import_log')
                ->where('entity_type', self::IMPORT_ENTITY_TYPE)
                ->whereIn('source_harvest_id', $chunk)
                ->get(['source_harvest_id', 'target_id']);

            foreach ($entries as $entry) {
                $sourceId = (string) $entry->source_harvest_id;
                if (isset($targets[$sourceId])) {
                    throw new RuntimeException("Import ledger contains duplicate records for {$sourceId}.");
                }

                if ($entry->target_id === null) {
                    throw new RuntimeException("Import ledger record {$sourceId} has no target time entry.");
                }

                $targets[$sourceId] = (int) $entry->target_id;
            }
        }

        return $targets;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $ledgerTargets
     */
    private function assertLedgerEntriesAreUnchanged(array $rows, array $ledgerTargets, bool $lockForUpdate = false): void
    {
        if ($ledgerTargets === []) {
            return;
        }

        $entries = [];
        foreach (array_chunk(array_values($ledgerTargets), 500) as $chunk) {
            $query = TimeEntry::query()->whereIn('id', $chunk);
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $entry) {
                $entries[$entry->id] = $entry;
            }
        }

        foreach ($rows as $row) {
            $targetId = $ledgerTargets[$row['source_id']] ?? null;
            if ($targetId === null) {
                continue;
            }

            $entry = $entries[$targetId] ?? null;
            if ($entry === null || ! $this->entryMatchesSource($entry, $row)) {
                throw new RuntimeException("Previously imported time entry {$targetId} for {$row['source_id']} is missing or has changed.");
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function entryMatchesSource(TimeEntry $entry, array $row): bool
    {
        $expectedRate = $row['is_billable'] ? (float) $row['billable_rate'] : null;
        $actualRate = $entry->billable_rate_snapshot !== null ? (float) $entry->billable_rate_snapshot : null;

        return $entry->project_id === (int) $row['project_id']
            && $entry->user_id === (int) $row['user_id']
            && $entry->task_id === (int) $row['task_id']
            && $entry->spent_on->toDateString() === $row['spent_on']
            && abs((float) $entry->hours - (float) $row['hours']) < 0.005
            && $entry->notes === $row['notes']
            && $entry->is_running === false
            && $entry->timer_started_at === null
            && $entry->is_billable === (bool) $row['is_billable']
            && (($expectedRate === null && $actualRate === null) || ($expectedRate !== null && $actualRate !== null && abs($expectedRate - $actualRate) < 0.005))
            && abs((float) $entry->billable_amount - (float) $row['billable_amount']) < 0.005
            && $entry->invoiced_at === null
            && $entry->external_reference === $row['external_reference']
            && $entry->asana_task_gid === null
            && $entry->asana_synced_at === null
            && $entry->asana_sync_error === null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $loggedTargetIds
     * @param  list<array<string, mixed>>|null  $lockScopeRows
     * @return list<array{target_code: string, spent_on: string, user_name: string, task_name: string, source_ids: list<string>, source_rows: int, source_hours: float, source_amount: float, existing_rows: int, existing_hours: float, existing_amount: float}>
     */
    private function conflicts(array $rows, array $loggedTargetIds, bool $lockForUpdate = false, ?array $lockScopeRows = null): array
    {
        if ($rows === []) {
            return [];
        }

        $sourceGroups = [];
        foreach ($rows as $row) {
            $key = $this->overlapKey($row);
            if (! isset($sourceGroups[$key])) {
                $sourceGroups[$key] = [
                    'target_code' => (string) $row['target_code'],
                    'spent_on' => (string) $row['spent_on'],
                    'user_name' => (string) $row['user_name'],
                    'task_name' => (string) $row['task_name'],
                    'source_ids' => [],
                    'source_rows' => 0,
                    'source_hours' => 0.0,
                    'source_amount' => 0.0,
                    'existing_rows' => 0,
                    'existing_hours' => 0.0,
                    'existing_amount' => 0.0,
                ];
            }
            $sourceGroups[$key]['source_ids'][] = (string) $row['source_id'];
            $sourceGroups[$key]['source_rows']++;
            $sourceGroups[$key]['source_hours'] += (float) $row['hours'];
            $sourceGroups[$key]['source_amount'] += (float) $row['billable_amount'];
        }

        $scopeRows = $lockScopeRows ?? $rows;
        $projectIds = array_values(array_unique(array_map(fn (array $row): int => (int) $row['project_id'], $scopeRows)));
        $query = TimeEntry::query()->whereIn('project_id', $projectIds);

        if (! $lockForUpdate) {
            $query->where('spent_on', '>=', min(array_column($rows, 'spent_on')))
                ->where('spent_on', '<=', self::CUTOFF);
        }

        if ($loggedTargetIds !== [] && ! $lockForUpdate) {
            $query->whereNotIn('id', $loggedTargetIds);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $loggedTargetLookup = array_fill_keys($loggedTargetIds, true);
        foreach ($query->get() as $entry) {
            if (isset($loggedTargetLookup[$entry->id])) {
                continue;
            }

            $key = implode('|', [$entry->project_id, $entry->spent_on->toDateString(), $entry->user_id, $entry->task_id]);
            if (! isset($sourceGroups[$key])) {
                continue;
            }
            $sourceGroups[$key]['existing_rows']++;
            $sourceGroups[$key]['existing_hours'] += (float) $entry->hours;
            $sourceGroups[$key]['existing_amount'] += (float) $entry->billable_amount;
        }

        return array_values(array_map(
            function (array $group): array {
                $group['source_hours'] = round($group['source_hours'], 2);
                $group['source_amount'] = round($group['source_amount'], 2);
                $group['existing_hours'] = round($group['existing_hours'], 2);
                $group['existing_amount'] = round($group['existing_amount'], 2);

                return $group;
            },
            array_filter($sourceGroups, fn (array $group): bool => $group['existing_rows'] > 0)
        ));
    }

    /** @param array<string, mixed> $row */
    private function overlapKey(array $row): string
    {
        return implode('|', [$row['project_id'], $row['spent_on'], $row['user_id'], $row['task_id']]);
    }

    /** @param list<array<string, mixed>> $approvedSkips */
    private function printApprovedSkips(array $approvedSkips): void
    {
        foreach ($approvedSkips as $skip) {
            $this->warn(sprintf(
                'APPROVED SKIP: %s %s / %s / %s - Harvest %.2f h, £%.2f; preserving %d existing row(s), %.2f h, £%.2f.',
                $skip['target_code'],
                $skip['spent_on'],
                $skip['user_name'],
                $skip['task_name'],
                $skip['source_hours'],
                $skip['source_amount'],
                $skip['existing_rows'],
                $skip['existing_hours'],
                $skip['existing_amount'],
            ));
            $this->line('  Source fingerprint: '.$skip['source_ids'][0]);
        }
    }

    /** @param list<array<string, mixed>> $conflicts */
    private function printConflicts(array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            $this->error(sprintf(
                'CONFLICT: %s %s / %s / %s - Harvest %d row(s), %.2f h, £%.2f; existing %d row(s), %.2f h, £%.2f.',
                $conflict['target_code'],
                $conflict['spent_on'],
                $conflict['user_name'],
                $conflict['task_name'],
                $conflict['source_rows'],
                $conflict['source_hours'],
                $conflict['source_amount'],
                $conflict['existing_rows'],
                $conflict['existing_hours'],
                $conflict['existing_amount'],
            ));
            $this->line('  Source fingerprint(s): '.implode(', ', $conflict['source_ids']));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $sourceRows
     * @param  list<array<string, mixed>>  $pendingRows
     */
    private function insertRows(array $rows, string $sourceHash, array $sourceRows, array $pendingRows): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->assertLockingIsolation();

        return DB::transaction(function () use ($rows, $sourceHash, $sourceRows, $pendingRows): int {
            Project::query()
                ->whereIn('id', array_values(array_unique(array_map(fn (array $row): int => (int) $row['project_id'], $sourceRows))))
                ->lockForUpdate()
                ->get();

            $this->assertLedgerMatchesSource($sourceRows, $sourceHash);
            $concurrentLedgerTargets = $this->ledgerTargets(array_column($rows, 'source_id'));
            if ($concurrentLedgerTargets !== []) {
                throw new RuntimeException('Import ledger changed while waiting for the transaction lock; rerun the dry run.');
            }

            $lockedLedgerTargets = $this->ledgerTargets(array_column($sourceRows, 'source_id'));
            $allConflicts = $this->conflicts($pendingRows, array_values($lockedLedgerTargets), true, $sourceRows);
            $approvedSkips = $this->validateApprovedSkips($sourceRows, $lockedLedgerTargets, $allConflicts);
            $lockedRowsToInsert = array_values(array_filter(
                $pendingRows,
                fn (array $row): bool => ! isset($lockedLedgerTargets[$row['source_id']])
                    && ! isset($approvedSkips[$row['source_id']])
            ));

            if (array_column($lockedRowsToInsert, 'source_id') !== array_column($rows, 'source_id')) {
                throw new RuntimeException('Import state changed while waiting for the transaction lock; rerun the dry run.');
            }

            if ($this->conflicts($lockedRowsToInsert, array_values($lockedLedgerTargets), true, $sourceRows) !== []) {
                throw new RuntimeException('Existing time entries changed after preflight; rerun the dry run.');
            }

            $this->assertLedgerEntriesAreUnchanged($sourceRows, $lockedLedgerTargets, true);

            $importedAt = now();
            foreach ($rows as $row) {
                /** @var TimeEntry $entry */
                $entry = TimeEntry::withoutEvents(fn (): TimeEntry => TimeEntry::query()->create([
                    'user_id' => $row['user_id'],
                    'project_id' => $row['project_id'],
                    'task_id' => $row['task_id'],
                    'spent_on' => $row['spent_on'],
                    'hours' => $row['hours'],
                    'notes' => $row['notes'],
                    'is_running' => false,
                    'timer_started_at' => null,
                    'is_billable' => $row['is_billable'],
                    'billable_rate_snapshot' => $row['is_billable'] ? $row['billable_rate'] : null,
                    'billable_amount' => $row['is_billable'] ? $row['billable_amount'] : 0.0,
                    'invoiced_at' => null,
                    'external_reference' => $row['external_reference'],
                    'asana_task_gid' => null,
                    'asana_synced_at' => null,
                    'asana_sync_error' => null,
                ]));

                DB::table('harvest_import_log')->insert([
                    'source_harvest_id' => $row['source_id'],
                    'imported_at' => $importedAt,
                    'entity_type' => self::IMPORT_ENTITY_TYPE,
                    'target_id' => $entry->id,
                    'notes' => json_encode([
                        'source_sha256' => $sourceHash,
                        'source_row' => $row['source_row'],
                        'target_code' => $row['target_code'],
                    ], JSON_THROW_ON_ERROR),
                ]);
            }

            return count($rows);
        });
    }

    private function assertLockingIsolation(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $state = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level');
        $isolation = strtoupper(str_replace(' ', '-', (string) ($state->isolation_level ?? '')));
        if (! in_array($isolation, ['REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new RuntimeException("Historical Harvest commit requires REPEATABLE-READ or SERIALIZABLE isolation; current level is {$isolation}.");
        }
    }
}
