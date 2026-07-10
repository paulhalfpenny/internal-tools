<?php

use App\Console\Commands\HistoricalHarvestTimeImportManifest;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest(historicalHarvestTestManifest())
    );
});

const HISTORICAL_HARVEST_HEADERS = [
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

/**
 * @return list<array{
 *     target_code: string,
 *     source_client: string,
 *     source_code: string,
 *     source_project: string,
 *     from: string|null,
 *     expected_rows: int,
 *     table_amount: int
 * }>
 */
function historicalHarvestTestManifest(): array
{
    return [
        ['target_code' => 'DEN004', 'source_client' => '123Dentist', 'source_code' => 'DEN004', 'source_project' => 'Continuous Improvements Retainer (September 2025 - August 2026)', 'from' => '2025-09-01', 'expected_rows' => 3, 'table_amount' => 300],
        ['target_code' => 'EAA001', 'source_client' => 'East Anglian Air Ambulance (EAAA)', 'source_code' => 'EAA001', 'source_project' => 'Continuous Improvements Retainer (August 2025 - July 2026)', 'from' => '2026-02-01', 'expected_rows' => 1, 'table_amount' => 100],
        ['target_code' => 'FUN006', 'source_client' => 'Fundraising Everywhere', 'source_code' => 'FUN008', 'source_project' => 'Continuous Improvements Retainer Uplift 2026', 'from' => '2026-06-01', 'expected_rows' => 2, 'table_amount' => 200],
        ['target_code' => 'HOP005', 'source_client' => 'Homeprotect', 'source_code' => 'HOP005', 'source_project' => 'WebMCP Project', 'from' => null, 'expected_rows' => 1, 'table_amount' => 100],
    ];
}

function createHistoricalHarvestReferences(): void
{
    foreach (historicalHarvestTestManifest() as $definition) {
        $client = Client::factory()->create(['name' => 'Target '.$definition['target_code']]);
        Project::factory()->create([
            'client_id' => $client->id,
            'code' => $definition['target_code'],
            'name' => 'Target '.$definition['target_code'],
        ]);
    }

    User::factory()->create(['name' => 'Import User']);
    User::factory()->create(['name' => 'Dave Page']);
    Task::factory()->create(['name' => 'Development']);
    Task::factory()->create(['name' => 'Design & UX']);
}

/** @return list<array<string, string>> */
function reconciledHistoricalHarvestRows(): array
{
    $rows = [];

    foreach (historicalHarvestTestManifest() as $definition) {
        $tableAmountPence = $definition['table_amount'] * 100;
        $basePence = intdiv($tableAmountPence, $definition['expected_rows']);
        $remainder = $tableAmountPence % $definition['expected_rows'];

        for ($index = 0; $index < $definition['expected_rows']; $index++) {
            $amountPence = $basePence + ($index < $remainder ? 1 : 0);
            $amount = $amountPence / 100;

            $rows[] = [
                'Date' => $definition['from'] ?? '2026-06-09',
                'Client' => $definition['source_client'],
                'Project' => $definition['source_project'],
                'Project Code' => $definition['source_code'],
                'Task' => 'Development',
                'Notes' => $definition['target_code'].' row '.$index,
                'Hours' => number_format($amount / 100, 2, '.', ''),
                'Billable?' => 'Yes',
                'Invoiced?' => 'No',
                'Approved?' => 'No',
                'First Name' => 'Import',
                'Last Name' => 'User',
                'Employee Id' => '',
                'Roles' => 'Developer',
                'Teams' => 'Delivery',
                'Employee?' => 'Yes',
                'Billable Rate' => '100.00',
                'Billable Amount' => number_format($amount, 2, '.', ''),
                'Cost Rate' => '0.00',
                'Cost Amount' => '0.00',
                'Currency' => 'British Pound - GBP',
                'External Reference URL' => '',
            ];
        }
    }

    return $rows;
}

/** @param array<string, string> $row */
function historicalHarvestSourceId(array $row, string $targetCode, int $occurrence = 1): string
{
    $values = array_map(fn (string $header): string => trim($row[$header]), HISTORICAL_HARVEST_HEADERS);
    $fingerprint = hash('sha256', json_encode([
        'target_code' => $targetCode,
        'row' => $values,
    ], JSON_THROW_ON_ERROR));

    return "historical-time:v1:{$fingerprint}:{$occurrence}";
}

/** @param list<array<string, string>> $rows */
function writeHistoricalHarvestCsv(string $path, array $rows): string
{
    $handle = fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException("Unable to create {$path}");
    }

    fputcsv($handle, HISTORICAL_HARVEST_HEADERS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv(
            $handle,
            array_map(fn (string $header): string => $row[$header], HISTORICAL_HARVEST_HEADERS),
            ',',
            '"',
            ''
        );
    }
    fclose($handle);

    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException("Unable to hash {$path}");
    }

    return $hash;
}

test('historical Harvest import dry-runs the reconciled target set without writing', function () {
    createHistoricalHarvestReferences();
    $path = storage_path('framework/testing/historical-harvest-reconciled.csv');
    $rows = reconciledHistoricalHarvestRows();
    $hash = writeHistoricalHarvestCsv($path, $rows);
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest(historicalHarvestTestManifest(), [], [], $hash)
    );

    $before = [
        'clients' => Client::count(),
        'projects' => Project::count(),
        'users' => User::count(),
        'tasks' => Task::count(),
    ];

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('7')
        ->expectsOutputToContain('FUN006');

    $rows[0]['Notes'] = 'Altered after approval';
    writeHistoricalHarvestCsv($path, $rows);
    unset($rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('does not match the approved source SHA-256');

    expect(TimeEntry::count())->toBe(0)
        ->and(DB::table('harvest_import_log')->count())->toBe(0)
        ->and(Client::count())->toBe($before['clients'])
        ->and(Project::count())->toBe($before['projects'])
        ->and(User::count())->toBe($before['users'])
        ->and(Task::count())->toBe($before['tasks']);
});

test('historical Harvest import commits mapped fields and only adds time entries and ledger rows', function () {
    Queue::fake();
    $manifest = historicalHarvestTestManifest();
    $manifest[0]['table_amount'] = 1300;
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest($manifest)
    );
    createHistoricalHarvestReferences();
    $rows = reconciledHistoricalHarvestRows();

    $rows[0]['Hours'] = '11.02';
    $rows[0]['Billable Amount'] = '1,102.00';
    $rows[1]['Hours'] = '0.99';
    $rows[1]['Billable Amount'] = '99.00';
    $rows[2]['Hours'] = '0.99';
    $rows[2]['Billable Amount'] = '99.00';
    $firstAmount = 1102.0;
    $rows[0]['First Name'] = 'David';
    $rows[0]['Last Name'] = 'Page';
    $rows[0]['Task'] = 'Design';
    $rows[0]['Notes'] = 'Imported note, with punctuation';
    $rows[0]['External Reference URL'] = 'https://app.asana.com/0/123/456';
    $firstHours = (float) $rows[0]['Hours'];

    $nonBillableAmount = (float) $rows[1]['Billable Amount'];
    $rows[1]['Billable?'] = 'No';
    $rows[1]['Billable Rate'] = '0.00';
    $rows[1]['Billable Amount'] = '0.00';
    $rows[2]['Hours'] = '1.98';
    $rows[2]['Billable Amount'] = number_format((float) $rows[2]['Billable Amount'] + $nonBillableAmount, 2, '.', '');

    $excluded = $rows[0];
    $excluded['Project'] = 'Postcode Risk Assessment Calculator';
    $excluded['Project Code'] = 'HOP005';
    $excluded['Client'] = 'Homeprotect';
    $rows[] = $excluded;

    $beforeStart = $rows[3];
    $beforeStart['Date'] = '2026-01-31';
    $beforeStart['Notes'] = 'Excluded before start';
    $rows[] = $beforeStart;

    $afterCutoff = $rows[0];
    $afterCutoff['Date'] = '2026-07-01';
    $afterCutoff['Notes'] = 'Excluded after cutoff';
    $rows[] = $afterCutoff;

    $path = storage_path('framework/testing/historical-harvest-commit.csv');
    $hash = writeHistoricalHarvestCsv($path, $rows);
    unset($rows);
    $existingProject = Project::query()->where('code', 'HOP005')->firstOrFail();
    $existing = TimeEntry::factory()->create([
        'project_id' => $existingProject->id,
        'user_id' => User::query()->where('name', 'Import User')->firstOrFail()->id,
        'task_id' => Task::query()->where('name', 'Development')->firstOrFail()->id,
        'spent_on' => '2025-01-01',
        'hours' => 1.0,
        'notes' => 'Unrelated existing entry',
    ]);
    $existingBefore = (array) DB::table('time_entries')->where('id', $existing->id)->first();
    $beforeProjects = Project::query()->orderBy('id')->get()->map->getAttributes()->all();
    $beforeCounts = [
        'clients' => Client::count(),
        'projects' => Project::count(),
        'users' => User::count(),
        'tasks' => Task::count(),
        'project_tasks' => DB::table('project_task')->count(),
    ];

    $this->artisan('app:one-off-historical-harvest-time', [
        'path' => $path,
        '--expected-sha256' => $hash,
        '--commit' => true,
    ])->assertSuccessful();

    expect(TimeEntry::count())->toBe(8)
        ->and(DB::table('harvest_import_log')->count())->toBe(7)
        ->and(Client::count())->toBe($beforeCounts['clients'])
        ->and(Project::count())->toBe($beforeCounts['projects'])
        ->and(User::count())->toBe($beforeCounts['users'])
        ->and(Task::count())->toBe($beforeCounts['tasks'])
        ->and(DB::table('project_task')->count())->toBe($beforeCounts['project_tasks'])
        ->and(Project::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeProjects)
        ->and((array) DB::table('time_entries')->where('id', $existing->id)->first())->toBe($existingBefore)
        ->and(TimeEntry::query()->whereIn('notes', ['Excluded before start', 'Excluded after cutoff'])->count())->toBe(0);

    $imported = TimeEntry::query()->where('notes', 'Imported note, with punctuation')->firstOrFail();
    expect($imported->project->code)->toBe('DEN004')
        ->and($imported->user->name)->toBe('Dave Page')
        ->and($imported->task->name)->toBe('Design & UX')
        ->and($imported->spent_on->toDateString())->toBe('2025-09-01')
        ->and((float) $imported->hours)->toBe($firstHours)
        ->and($imported->notes)->toBe('Imported note, with punctuation')
        ->and($imported->is_running)->toBeFalse()
        ->and($imported->timer_started_at)->toBeNull()
        ->and($imported->is_billable)->toBeTrue()
        ->and((float) $imported->billable_rate_snapshot)->toBe(100.0)
        ->and((float) $imported->billable_amount)->toBe($firstAmount)
        ->and($imported->invoiced_at)->toBeNull()
        ->and($imported->external_reference)->toBe('https://app.asana.com/0/123/456')
        ->and($imported->asana_task_gid)->toBeNull()
        ->and($imported->asana_synced_at)->toBeNull()
        ->and($imported->asana_sync_error)->toBeNull();

    $nonBillable = TimeEntry::query()->where('notes', 'DEN004 row 1')->firstOrFail();
    expect($nonBillable->is_billable)->toBeFalse()
        ->and($nonBillable->billable_rate_snapshot)->toBeNull()
        ->and((float) $nonBillable->billable_amount)->toBe(0.0)
        ->and(TimeEntry::query()->whereHas('project', fn ($query) => $query->where('code', 'FUN006'))->count())->toBe(2)
        ->and(TimeEntry::query()->whereHas('project', fn ($query) => $query->where('code', 'EAA001'))->count())->toBe(1);

    Queue::assertNotPushed(SyncAsanaTaskHoursJob::class);
});

test('historical Harvest import is idempotent and preserves identical source events', function () {
    createHistoricalHarvestReferences();
    $rows = reconciledHistoricalHarvestRows();
    $rows[1] = $rows[0];

    $path = storage_path('framework/testing/historical-harvest-idempotent.csv');
    $hash = writeHistoricalHarvestCsv($path, $rows);
    unset($rows);
    $arguments = ['path' => $path, '--expected-sha256' => $hash, '--commit' => true];

    $this->artisan('app:one-off-historical-harvest-time', $arguments)->assertSuccessful();
    $this->artisan('app:one-off-historical-harvest-time', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Already imported: 7');

    $changedRows = reconciledHistoricalHarvestRows();
    $changedRows[1] = $changedRows[0];
    $changedRows[0]['Date'] = '2025-09-02';
    $changedPath = storage_path('framework/testing/historical-harvest-changed-source.csv');
    $changedHash = writeHistoricalHarvestCsv($changedPath, $changedRows);
    unset($changedRows);

    $this->artisan('app:one-off-historical-harvest-time', [
        'path' => $changedPath,
        '--expected-sha256' => $changedHash,
        '--commit' => true,
    ])->assertFailed()->expectsOutputToContain('belongs to source SHA-256');

    expect(TimeEntry::count())->toBe(7)
        ->and(DB::table('harvest_import_log')->count())->toBe(7)
        ->and(DB::table('harvest_import_log')->distinct()->count('source_harvest_id'))->toBe(7)
        ->and(TimeEntry::query()->where('notes', 'DEN004 row 0')->count())->toBe(2);
});

test('historical Harvest import aborts on an untracked existing-entry overlap without changing it', function () {
    createHistoricalHarvestReferences();
    $rows = reconciledHistoricalHarvestRows();
    $rows[1]['Date'] = '2025-09-02';
    $rows[2]['Date'] = '2025-09-03';
    $sourceId = historicalHarvestSourceId($rows[0], 'DEN004');
    $skip = [
        'source_id' => $sourceId,
        'target_code' => 'DEN004',
        'spent_on' => '2025-09-01',
        'user_name' => 'Import User',
        'task_name' => 'Development',
        'source_hours' => 1.0,
        'source_amount' => 100.0,
        'existing_rows' => 1,
        'existing_hours' => 7.0,
        'existing_amount' => 700.0,
    ];
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest(historicalHarvestTestManifest(), [], [$skip])
    );
    $project = Project::query()->where('code', 'DEN004')->firstOrFail();
    $user = User::query()->where('name', 'Import User')->firstOrFail();
    $task = Task::query()->where('name', 'Development')->firstOrFail();
    $existing = TimeEntry::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'task_id' => $task->id,
        'spent_on' => '2025-09-01',
        'hours' => 7.00,
        'notes' => 'Existing entry',
        'billable_rate_snapshot' => 100.00,
        'billable_amount' => 699.99,
    ]);
    $unapproved = TimeEntry::factory()->create([
        'project_id' => Project::query()->where('code', 'EAA001')->firstOrFail()->id,
        'user_id' => $user->id,
        'task_id' => $task->id,
        'spent_on' => '2026-02-01',
        'hours' => 2.00,
        'notes' => 'Unapproved overlap',
        'billable_rate_snapshot' => 100.00,
        'billable_amount' => 200.00,
    ]);

    $path = storage_path('framework/testing/historical-harvest-overlap.csv');
    $hash = writeHistoricalHarvestCsv($path, $rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('approved skip no longer matches');

    $existing->update(['billable_amount' => 700.00]);
    $before = (array) DB::table('time_entries')->where('id', $existing->id)->first();
    $unapprovedBefore = (array) DB::table('time_entries')->where('id', $unapproved->id)->first();

    $exitCode = Artisan::call('app:one-off-historical-harvest-time', ['path' => $path]);
    $output = Artisan::output();
    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('APPROVED SKIP: DEN004 2025-09-01')
        ->and($output)->toContain('CONFLICT: EAA001 2026-02-01');

    expect((array) DB::table('time_entries')->where('id', $existing->id)->first())->toBe($before)
        ->and((array) DB::table('time_entries')->where('id', $unapproved->id)->first())->toBe($unapprovedBefore)
        ->and(DB::table('harvest_import_log')->count())->toBe(0);

    $unapproved->delete();
    $arguments = ['path' => $path, '--expected-sha256' => $hash, '--commit' => true];

    $this->artisan('app:one-off-historical-harvest-time', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Approved skips: 1');
    $this->artisan('app:one-off-historical-harvest-time', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Imported: 0');

    expect(TimeEntry::count())->toBe(7)
        ->and((array) DB::table('time_entries')->where('id', $existing->id)->first())->toBe($before)
        ->and(DB::table('harvest_import_log')->count())->toBe(6);
});

test('historical Harvest import blocks mismatched approved row counts or totals', function () {
    createHistoricalHarvestReferences();
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest(
            historicalHarvestTestManifest(),
            [['target_code' => 'DEN004', 'csv_amount' => 938.0]]
        )
    );
    $rows = reconciledHistoricalHarvestRows();
    array_pop($rows);
    $path = storage_path('framework/testing/historical-harvest-row-count-mismatch.csv');
    writeHistoricalHarvestCsv($path, $rows);
    unset($rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('HOP005')
        ->expectsOutputToContain('Import is blocked');

    $rows = reconciledHistoricalHarvestRows();
    $path = storage_path('framework/testing/historical-harvest-old-table-total.csv');
    writeHistoricalHarvestCsv($path, $rows);
    unset($rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('DEN004')
        ->expectsOutputToContain('Import is blocked');

    $rows = reconciledHistoricalHarvestRows();
    $rows[0]['Billable Amount'] = number_format((float) $rows[0]['Billable Amount'] + 637.99, 2, '.', '');
    $path = storage_path('framework/testing/historical-harvest-mismatch.csv');
    writeHistoricalHarvestCsv($path, $rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('DEN004')
        ->expectsOutputToContain('Import is blocked');

    $rows[0]['Billable Amount'] = number_format((float) $rows[0]['Billable Amount'] + 0.01, 2, '.', '');
    writeHistoricalHarvestCsv($path, $rows);
    unset($rows);
    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('APPROVED');

    expect(TimeEntry::count())->toBe(0)
        ->and(DB::table('harvest_import_log')->count())->toBe(0);
});

test('historical Harvest import rejects unsafe commit and source conditions before writing', function () {
    createHistoricalHarvestReferences();
    $path = storage_path('framework/testing/historical-harvest-hash.csv');
    writeHistoricalHarvestCsv($path, reconciledHistoricalHarvestRows());

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path, '--commit' => true])
        ->assertFailed()
        ->expectsOutputToContain('--expected-sha256 is required');

    $this->artisan('app:one-off-historical-harvest-time', [
        'path' => $path,
        '--expected-sha256' => str_repeat('0', 64),
        '--commit' => true,
    ])->assertFailed()->expectsOutputToContain('Source SHA-256 mismatch');

    $path = storage_path('framework/testing/historical-harvest-missing-header.csv');
    $headers = array_values(array_filter(HISTORICAL_HARVEST_HEADERS, fn (string $header): bool => $header !== 'Teams'));
    $handle = fopen($path, 'w');
    expect($handle)->not->toBeFalse();
    fputcsv($handle, $headers, ',', '"', '');
    fclose($handle);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('Missing required CSV header(s): Teams');

    $rows = reconciledHistoricalHarvestRows();
    $rows[0]['Date'] = 'not-a-date';
    $path = storage_path('framework/testing/historical-harvest-invalid-date.csv');
    writeHistoricalHarvestCsv($path, $rows);
    unset($rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('CSV row 2 has an invalid Date value');

    $rows = reconciledHistoricalHarvestRows();
    $rows[0]['Cost Rate'] = 'not-a-rate';
    $path = storage_path('framework/testing/historical-harvest-invalid-cost.csv');
    writeHistoricalHarvestCsv($path, $rows);
    unset($rows);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('CSV row 2 has an invalid Cost Rate');

    foreach (['Invoiced?', 'Approved?'] as $field) {
        $rows = reconciledHistoricalHarvestRows();
        $rows[0][$field] = 'Yes';
        $path = storage_path('framework/testing/historical-harvest-'.strtolower(rtrim($field, '?')).'.csv');
        writeHistoricalHarvestCsv($path, $rows);
        unset($rows);

        $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
            ->assertFailed()
            ->expectsOutputToContain('is invoiced or approved');
    }

    User::query()->where('name', 'Import User')->delete();
    $path = storage_path('framework/testing/historical-harvest-missing-user.csv');
    writeHistoricalHarvestCsv($path, reconciledHistoricalHarvestRows());

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('Expected exactly one Internal Tools user named Import User; found 0');

    expect(TimeEntry::count())->toBe(0)
        ->and(DB::table('harvest_import_log')->count())->toBe(0);
});

test('historical Harvest import validates ledger metadata and its imported time entries', function () {
    createHistoricalHarvestReferences();
    $path = storage_path('framework/testing/historical-harvest-edited-ledger.csv');
    $hash = writeHistoricalHarvestCsv($path, reconciledHistoricalHarvestRows());

    $this->artisan('app:one-off-historical-harvest-time', [
        'path' => $path,
        '--expected-sha256' => $hash,
        '--commit' => true,
    ])->assertSuccessful();

    $ledger = DB::table('harvest_import_log')->orderBy('id')->first();
    expect($ledger)->not->toBeNull();
    DB::table('harvest_import_log')->where('id', $ledger->id)->update(['notes' => '{"source_sha256":"invalid"}']);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('has invalid metadata');

    DB::table('harvest_import_log')->where('id', $ledger->id)->update(['notes' => $ledger->notes]);
    $extraLedgerId = DB::table('harvest_import_log')->insertGetId([
        'source_harvest_id' => 'historical-time:v1:untracked:1',
        'imported_at' => now(),
        'entity_type' => 'historical_harvest_time_entry',
        'target_id' => $ledger->target_id,
        'notes' => $ledger->notes,
    ]);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('does not match the current source');

    DB::table('harvest_import_log')->where('id', $extraLedgerId)->delete();

    TimeEntry::query()->orderBy('id')->firstOrFail()->update(['notes' => 'Changed after import']);

    $this->artisan('app:one-off-historical-harvest-time', ['path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('is missing or has changed');

    expect(TimeEntry::count())->toBe(7)
        ->and(DB::table('harvest_import_log')->count())->toBe(7);
});

test('production historical Harvest manifest pins every approved source mapping', function () {
    $manifest = new HistoricalHarvestTimeImportManifest;

    expect($manifest->mappings())->toBe([
        ['target_code' => 'DEN004', 'source_client' => '123Dentist', 'source_code' => 'DEN004', 'source_project' => 'Continuous Improvements Retainer (September 2025 - August 2026)', 'from' => '2025-09-01', 'expected_rows' => 282, 'table_amount' => 24105],
        ['target_code' => 'CEP001', 'source_client' => 'CEPA', 'source_code' => 'CEP001', 'source_project' => 'Website Maintenance Retainer (January 2026 - December 2026)', 'from' => '2026-01-01', 'expected_rows' => 165, 'table_amount' => 11025],
        ['target_code' => 'EAA001', 'source_client' => 'East Anglian Air Ambulance (EAAA)', 'source_code' => 'EAA001', 'source_project' => 'Continuous Improvements Retainer (August 2025 - July 2026)', 'from' => '2026-02-01', 'expected_rows' => 134, 'table_amount' => 10144],
        ['target_code' => 'ZED002', 'source_client' => 'Criterion Hospitality', 'source_code' => 'ZED002', 'source_project' => 'Zedwell Hotels - Continuous Improvement Retainer (December 2025 - November 2026)', 'from' => '2025-12-01', 'expected_rows' => 231, 'table_amount' => 17979],
        ['target_code' => 'HOP003', 'source_client' => 'Homeprotect', 'source_code' => 'HOP003', 'source_project' => 'Continuous Improvements', 'from' => '2026-05-01', 'expected_rows' => 70, 'table_amount' => 3863],
        ['target_code' => 'FUN006', 'source_client' => 'Fundraising Everywhere', 'source_code' => 'FUN008', 'source_project' => 'Continuous Improvements Retainer Uplift 2026', 'from' => '2026-06-01', 'expected_rows' => 73, 'table_amount' => 6847],
        ['target_code' => 'MED001', 'source_client' => 'Medivet', 'source_code' => 'MED001', 'source_project' => 'Digital Retainer FY27', 'from' => '2026-05-01', 'expected_rows' => 145, 'table_amount' => 16179],
        ['target_code' => 'AAB003', 'source_client' => 'AAB', 'source_code' => 'AAB003', 'source_project' => 'CRO Improvements + CR for Teams + Completion of CRO Project SOW - Build Phase', 'from' => null, 'expected_rows' => 228, 'table_amount' => 36715],
        ['target_code' => 'TOG013', 'source_client' => 'Tomorrows Guides', 'source_code' => 'TOG013', 'source_project' => "Tomorrow's Guides - Dynamic Care Home Fees", 'from' => null, 'expected_rows' => 5, 'table_amount' => 1083],
        ['target_code' => 'TOG012', 'source_client' => 'Tomorrows Guides', 'source_code' => 'TOG012', 'source_project' => 'CRO Improvements - carehome.co.uk - Build Phase', 'from' => null, 'expected_rows' => 96, 'table_amount' => 14947],
        ['target_code' => 'HOP005', 'source_client' => 'Homeprotect', 'source_code' => 'HOP005', 'source_project' => 'WebMCP Project', 'from' => null, 'expected_rows' => 15, 'table_amount' => 1337],
        ['target_code' => 'MED057', 'source_client' => 'Medivet', 'source_code' => 'MED057', 'source_project' => 'Key Modules Articles - Content Updates', 'from' => null, 'expected_rows' => 6, 'table_amount' => 1200],
    ])->and($manifest->expectedSourceSha256())->toBe('787ea385c7fc83aadf815df1a979082bcd878f5bd4fa1a6c404dd8a594ac96b5')
        ->and($manifest->approvedAmountExceptions())->toBe([
            ['target_code' => 'DEN004', 'csv_amount' => 24743.0],
            ['target_code' => 'EAA001', 'csv_amount' => 10884.5],
        ])->and($manifest->approvedSkips())->toBe([
            [
                'source_id' => 'historical-time:v1:2711fc10ba973071d9914fb40d3f97a9e4e0b895dff7d51a6d35ff1eafdd9cb6:1',
                'target_code' => 'MED001',
                'spent_on' => '2026-06-29',
                'user_name' => 'Chris Parsons',
                'task_name' => 'Development',
                'source_hours' => 6.0,
                'source_amount' => 600.0,
                'existing_rows' => 1,
                'existing_hours' => 7.5,
                'existing_amount' => 750.0,
            ],
            [
                'source_id' => 'historical-time:v1:fe2bf1b23a1557692d23c2dd8f8acb6df82d04cf5a0d8ea42dc23a2d952a05ae:1',
                'target_code' => 'AAB003',
                'spent_on' => '2026-06-29',
                'user_name' => 'Hayk Sargsyan',
                'task_name' => 'Development',
                'source_hours' => 7.0,
                'source_amount' => 700.0,
                'existing_rows' => 4,
                'existing_hours' => 7.0,
                'existing_amount' => 700.0,
            ],
            [
                'source_id' => 'historical-time:v1:3b89388219ac026137a1f2306eb540126813ea4b5f880b127a4ca05ed5aaee5f:1',
                'target_code' => 'AAB003',
                'spent_on' => '2026-06-30',
                'user_name' => 'Hayk Sargsyan',
                'task_name' => 'Development',
                'source_hours' => 7.75,
                'source_amount' => 775.0,
                'existing_rows' => 2,
                'existing_hours' => 7.0,
                'existing_amount' => 700.0,
            ],
        ]);
});
