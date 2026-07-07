<?php

namespace App\Console\Commands;

use App\Enums\BudgetType;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SplFileObject;

class OneOffProjectBudgetBalances extends Command
{
    protected $signature = 'app:one-off-project-budget-balances
        {path : XLSX or CSV export of the Harvest transfer sheet}
        {--as-of=2026-06-30 : Sheet balance snapshot date}
        {--strategy=report : report, existing, or reset}
        {--tolerance=1.00 : Allowed GBP difference for existing-model reconciliation}
        {--commit : Persist the selected strategy instead of dry-running}';

    protected $description = 'One-off reconciliation/import of end-of-June 2026 project budget balances.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $strategy = (string) $this->option('strategy');
        if (! in_array($strategy, ['report', 'existing', 'reset'], true)) {
            $this->error('Strategy must be one of: report, existing, reset.');

            return self::FAILURE;
        }

        $asOf = CarbonImmutable::parse((string) $this->option('as-of'))->endOfDay();
        $tolerance = (float) $this->option('tolerance');
        $commit = (bool) $this->option('commit');
        $sheetRows = collect($this->sheetRows($path));

        if ($sheetRows->isEmpty()) {
            $this->error('No coded budget rows found in the sheet.');

            return self::FAILURE;
        }

        $projects = Project::query()
            ->whereIn('code', $sheetRows->pluck('code')->all())
            ->get()
            ->keyBy(fn (Project $project): string => (string) $project->code);

        $missing = $sheetRows
            ->reject(fn (array $row): bool => $projects->has($row['code']))
            ->pluck('code')
            ->values();

        if ($missing->isNotEmpty()) {
            foreach ($missing as $code) {
                $this->error("Missing project for code {$code}");
            }

            return self::FAILURE;
        }

        $rows = $this->reconciledRows($sheetRows, $projects, $asOf);
        $this->printReport($rows, $strategy, $commit, $tolerance);

        if ($strategy === 'report') {
            return self::SUCCESS;
        }

        $blocked = $this->blockingRows($rows, $strategy, $tolerance);
        if ($blocked->isNotEmpty()) {
            $this->error("Strategy {$strategy} cannot be committed until blocking rows are resolved.");

            return self::FAILURE;
        }

        if (! $commit) {
            $this->warn('DRY RUN only. Re-run with --commit to persist this strategy.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $strategy, $asOf): void {
            foreach ($rows as $row) {
                /** @var Project $project */
                $project = $row['project'];
                $project->update($strategy === 'existing'
                    ? $this->existingAttributes($row)
                    : $this->resetAttributes($row, $asOf));
            }
        });

        $this->info("Updated {$rows->count()} project budget(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sheetRows(string $path): array
    {
        $rows = str_ends_with(strtolower($path), '.xlsx')
            ? $this->xlsxRows($path)
            : $this->csvRows($path);

        $section = null;
        $parsed = [];

        foreach ($rows as $row) {
            $row = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $row);
            $first = (string) ($row[0] ?? '');
            $second = (string) ($row[1] ?? '');

            if ($first === 'Job Codes' && $second === 'CI Client') {
                $section = 'ci';

                continue;
            }

            if ($first === 'Job Codes' && $second === 'Projects In Play') {
                $section = 'fixed';

                continue;
            }

            if ($section === null || $first === '') {
                continue;
            }

            if ($section === 'ci') {
                [$start, $end] = $this->parseDuration((string) ($row[2] ?? ''));
                $monthly = $this->money($row[4] ?? null);
                $allocation = $this->money($row[5] ?? null);
                $spend = $this->money($row[6] ?? null);
                $parsed[] = [
                    'code' => $first,
                    'label' => $second,
                    'kind' => 'ci',
                    'contract_start' => $start,
                    'contract_end' => $end,
                    'monthly_amount' => $monthly,
                    'sheet_allocation' => $allocation,
                    'sheet_spend' => $spend,
                    'sheet_remaining' => round($allocation - $spend, 2),
                ];

                continue;
            }

            $total = $this->money($row[2] ?? null);
            $spend = $this->money($row[3] ?? null);
            $parsed[] = [
                'code' => $first,
                'label' => $second,
                'kind' => 'fixed',
                'contract_start' => null,
                'contract_end' => null,
                'monthly_amount' => null,
                'sheet_allocation' => $total,
                'sheet_spend' => $spend,
                'sheet_remaining' => round($total - $spend, 2),
            ];
        }

        return $parsed;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sheetRows
     * @param  Collection<string, Project>  $projects
     * @return Collection<int, array<string, mixed>>
     */
    private function reconciledRows(Collection $sheetRows, Collection $projects, CarbonImmutable $asOf): Collection
    {
        return $sheetRows->map(function (array $row) use ($projects, $asOf): array {
            /** @var Project $project */
            $project = $projects->get($row['code']);
            $actualSpend = (float) TimeEntry::query()
                ->where('project_id', $project->id)
                ->where('is_billable', true)
                ->where('spent_on', '<=', $asOf->toDateString())
                ->sum('billable_amount');

            $modelAllocation = $row['kind'] === 'ci'
                ? $this->modelCiAllocation($row, $asOf)
                : (float) $row['sheet_allocation'];

            return [
                ...$row,
                'project' => $project,
                'actual_spend' => round($actualSpend, 2),
                'spend_diff' => round($actualSpend - (float) $row['sheet_spend'], 2),
                'model_allocation' => round($modelAllocation, 2),
                'allocation_diff' => round($modelAllocation - (float) $row['sheet_allocation'], 2),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function blockingRows(Collection $rows, string $strategy, float $tolerance): Collection
    {
        if ($strategy !== 'existing') {
            return collect();
        }

        return $rows->filter(function (array $row) use ($tolerance): bool {
            if ($row['kind'] === 'ci' && $row['contract_start'] === null) {
                return true;
            }

            return abs((float) $row['spend_diff']) > $tolerance
                || abs((float) $row['allocation_diff']) > $tolerance;
        })->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function existingAttributes(array $row): array
    {
        if ($row['kind'] === 'ci') {
            return [
                'budget_type' => BudgetType::MonthlyCi,
                'budget_amount' => $row['monthly_amount'],
                'budget_hours' => null,
                'budget_starts_on' => $row['contract_start'],
                'starts_on' => $row['contract_start'],
                'ends_on' => $row['contract_end'],
            ];
        }

        return [
            'budget_type' => BudgetType::FixedFee,
            'budget_amount' => $row['sheet_allocation'],
            'budget_hours' => null,
            'budget_starts_on' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resetAttributes(array $row, CarbonImmutable $asOf): array
    {
        $attributes = [
            'budget_type' => BudgetType::FixedFee,
            'budget_amount' => max(0.0, (float) $row['sheet_remaining']),
            'budget_hours' => null,
            'budget_starts_on' => $asOf->addDay()->toDateString(),
        ];

        if ($row['contract_end'] !== null) {
            $attributes['ends_on'] = $row['contract_end'];
        }

        return $attributes;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function printReport(Collection $rows, string $strategy, bool $commit, float $tolerance): void
    {
        $this->info(($commit ? 'COMMIT' : 'DRY RUN').' strategy='.$strategy.' tolerance='.$tolerance);

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s %-8s sheet spend %0.2f actual %0.2f diff %+0.2f sheet allocation %0.2f model %0.2f diff %+0.2f remaining %+0.2f',
                strtoupper((string) $row['kind']),
                (string) $row['code'],
                (float) $row['sheet_spend'],
                (float) $row['actual_spend'],
                (float) $row['spend_diff'],
                (float) $row['sheet_allocation'],
                (float) $row['model_allocation'],
                (float) $row['allocation_diff'],
                (float) $row['sheet_remaining'],
            ));
        }

        $this->info('matched '.$rows->count().' row(s)');
    }

    /** @return array<int, array<int, mixed>> */
    private function csvRows(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $rows = [];
        foreach ($file as $row) {
            if (is_array($row) && $row !== [null]) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, mixed>> */
    private function xlsxRows(string $path): array
    {
        $workbook = IOFactory::load($path);

        return $workbook->getSheet(0)->toArray(null, true, true, false);
    }

    private function money(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return round((float) $value, 2);
        }

        preg_match('/-?[\d,]+(?:\.\d+)?/', (string) $value, $matches);

        return isset($matches[0]) ? round((float) str_replace(',', '', $matches[0]), 2) : 0.0;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseDuration(string $duration): array
    {
        if (preg_match('/^(?<start>[A-Za-z]+)\s*(?<startYear>\d{2})?\s*-\s*(?<end>[A-Za-z]+)\s*(?<endYear>\d{2,4})$/', trim($duration), $matches) !== 1) {
            return [null, null];
        }

        $endYear = $this->year((string) $matches['endYear']);
        $startYear = isset($matches['startYear']) && $matches['startYear'] !== ''
            ? $this->year((string) $matches['startYear'])
            : $endYear;
        $startMonth = $this->month((string) $matches['start']);
        $endMonth = $this->month((string) $matches['end']);

        if ($startMonth > $endMonth && (! isset($matches['startYear']) || $matches['startYear'] === '')) {
            $startYear--;
        }

        return [
            CarbonImmutable::create($startYear, $startMonth, 1)->toDateString(),
            CarbonImmutable::create($endYear, $endMonth, 1)->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function modelCiAllocation(array $row, CarbonImmutable $asOf): float
    {
        if ($row['contract_start'] === null) {
            return 0.0;
        }

        $startMonth = CarbonImmutable::parse((string) $row['contract_start'])->startOfMonth();
        $asOfMonth = $asOf->startOfMonth();

        if ($asOfMonth->lessThan($startMonth)) {
            return 0.0;
        }

        return ((int) $startMonth->diffInMonths($asOfMonth) + 1) * (float) $row['monthly_amount'];
    }

    private function month(string $name): int
    {
        return CarbonImmutable::parse('1 '.$name.' 2026')->month;
    }

    private function year(string $year): int
    {
        return strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
    }
}
