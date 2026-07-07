# One-Off Budget Balance Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Load Rachel's end-of-June 2026 Harvest balance sheet into existing project budget fields without adding schema, UI, or permanent opening-balance product features.

**Architecture:** Add one dry-run-first Artisan command that parses the XLSX/CSV transfer sheet, matches rows to projects by code, compares sheet balances with current Internal Tools billable actuals, and only commits an update when the chosen strategy is explicit. Strategy `existing` uses the current budget model when it reconciles cleanly; strategy `reset` sets a future fixed-fee pot from 2026-07-01 using the sheet's remaining balance. No migrations, calculator changes, Livewire UI changes, or MCP changes are included.

**Tech Stack:** Laravel 11, Eloquent, Pest feature tests, PhpSpreadsheet through the existing `maatwebsite/excel` dependency, current `projects` and `time_entries` tables.

---

## Why This Is Smaller

This plan deliberately avoids the larger opening-balance model. It treats the ticket as a one-off operational correction:

- Use existing fields: `budget_type`, `budget_amount`, `budget_hours`, `budget_starts_on`, `starts_on`, `ends_on`.
- Add only one command and one focused test file.
- Dry-run before any write.
- Refuse the "nice" existing-model update when the current model cannot reproduce the sheet.
- Fall back to a reset model that starts from the remaining balance on `2026-07-01`.

## Decision Rule

Run the command in dry-run mode first.

1. If `existing` reconciliation passes for all rows, apply `--strategy=existing --commit`.
2. If `existing` fails because sheet allocation/spend cannot be represented by current historical data, review the mismatches.
3. If the business goal is simply "future work draws down from the remaining balance", apply `--strategy=reset --commit`.
4. Before running either `--commit` command, take and verify a fresh database backup.
5. If Rachel needs historical over/under-spend preserved exactly in reports, stop and use the fuller opening-balance plan instead.

## Source Data

Use `/Users/paulhalfpenny/Downloads/Harvest Transfer - CI Retainers & Active Projects to End of June 2026.xlsx`.

The useful coded rows are:

| Code | Type | Monthly/total | Sheet allocation | Sheet spend | Sheet remaining |
| --- | --- | ---: | ---: | ---: | ---: |
| DEN004 | CI | 2400.00 | 28800.00 | 24105.00 | 4695.00 |
| CEP001 | CI | 2400.00 | 14000.00 | 11025.00 | 2975.00 |
| EAAA001 | CI | 2000.00 | 10000.00 | 10144.00 | -144.00 |
| ZED002 | CI | 2000.00 | 14000.00 | 17979.00 | -3979.00 |
| HOP003 | CI | 2400.00 | 4800.00 | 3863.00 | 937.00 |
| FUN006 | CI | 4800.00 | 4800.00 | 6847.00 | -2047.00 |
| MED001 | CI | 8800.00 | 17600.00 | 16179.00 | 1421.00 |
| AAB003 | fixed | 26000.00 | 26000.00 | 36715.00 | -10715.00 |
| TOG013 | fixed | 5600.00 | 5600.00 | 1083.00 | 4517.00 |
| TOG012 | fixed | 15500.00 | 15500.00 | 14947.00 | 553.00 |
| HOP005 | fixed | 5500.00 | 5500.00 | 1337.00 | 4163.00 |
| MED057 | fixed | 6000.00 | 6000.00 | 1200.00 | 4800.00 |

Rows without a job code and rows labelled "For our records only" are ignored.

---

## File Structure

- **Create** `app/Console/Commands/OneOffProjectBudgetBalances.php` - parser, reconciliation report, and explicit commit strategies in one file.
- **Test** `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php` - command behaviour for dry-run, existing strategy, reset strategy, and mismatches.
- **No changes** to migrations, models, budget calculator, Livewire views, MCP tools, or existing report code.

---

### Task 1: Dry-Run Reconciliation Command

**Files:**
- Create: `app/Console/Commands/OneOffProjectBudgetBalances.php`
- Test: `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`

- [ ] **Step 1: Write the failing dry-run tests**

Create `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`:

```php
<?php

use App\Enums\BudgetType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeOneOffBudgetCsv(string $path): void
{
    file_put_contents($path, implode("\n", [
        'Job Codes,CI Client,Contracted Duration,Is it rolling?,Monthly Retainer GBP,Expected total retainer amount to end of June,Total spend to end of June',
        'HOP003,Homeprotect,May 26 - April 27,"Yes, year on year",2400,4800,3863',
        ',Homeprotect previous,May 25 - April 26,"For our records only",2400,28800,24298',
        '',
        'Job Codes,Projects In Play,Total Project Cost,Total spend to end of June',
        'HOP005,HP WebMCP,5500,1337',
    ]));
}

function oneOffBudgetEntry(Project $project, string $spentOn, float $amount): void
{
    $user = User::factory()->create();
    $task = Task::factory()->create();

    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $spentOn,
        'hours' => 1.00,
        'is_billable' => true,
        'billable_rate_snapshot' => $amount,
        'billable_amount' => $amount,
    ]);
}

test('one off budget balance command dry-runs matched rows without updating projects', function () {
    $ci = Project::factory()->create(['code' => 'HOP003', 'name' => 'Homeprotect Retainer']);
    $fixed = Project::factory()->create(['code' => 'HOP005', 'name' => 'HP WebMCP']);
    oneOffBudgetEntry($ci, '2026-05-10', 2371.00);
    oneOffBudgetEntry($ci, '2026-06-10', 1492.00);
    oneOffBudgetEntry($fixed, '2026-06-12', 1337.00);

    $path = storage_path('framework/testing/one-off-budget-balances.csv');
    writeOneOffBudgetCsv($path);

    $this->artisan('app:one-off-project-budget-balances', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('HOP003')
        ->expectsOutputToContain('HOP005')
        ->expectsOutputToContain('matched 2 row(s)');

    expect($ci->fresh()->budget_type)->toBeNull()
        ->and($fixed->fresh()->budget_type)->toBeNull();
});

test('one off budget balance command fails when a coded row has no project', function () {
    $path = storage_path('framework/testing/one-off-budget-balances.csv');
    writeOneOffBudgetCsv($path);

    $this->artisan('app:one-off-project-budget-balances', ['path' => $path])
        ->assertExitCode(1)
        ->expectsOutputToContain('Missing project for code HOP003')
        ->expectsOutputToContain('Missing project for code HOP005');
});
```

- [ ] **Step 2: Run the dry-run tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/Console/OneOffProjectBudgetBalancesTest.php --filter="dry-runs|coded row"
```

Expected: FAIL because `app:one-off-project-budget-balances` does not exist.

- [ ] **Step 3: Create the command class**

Create `app/Console/Commands/OneOffProjectBudgetBalances.php`:

```php
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
        if ($strategy === 'reset') {
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
        return [
            'budget_type' => BudgetType::FixedFee,
            'budget_amount' => max(0.0, (float) $row['sheet_remaining']),
            'budget_hours' => null,
            'budget_starts_on' => $asOf->addDay()->toDateString(),
            'ends_on' => $row['contract_end'] ?? null,
        ];
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
```

- [ ] **Step 4: Run dry-run tests**

Run:

```bash
php artisan test tests/Feature/Console/OneOffProjectBudgetBalancesTest.php --filter="dry-runs|coded row"
```

Expected: PASS.

---

### Task 2: Existing-Model Commit Strategy

**Files:**
- Modify: `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`
- Modify: `app/Console/Commands/OneOffProjectBudgetBalances.php` only if Task 1 code needs correction.

- [ ] **Step 1: Add tests for existing strategy**

Append to `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`:

```php
test('existing strategy commits when sheet spend and model allocation reconcile', function () {
    $ci = Project::factory()->create(['code' => 'HOP003']);
    $fixed = Project::factory()->create(['code' => 'HOP005']);
    oneOffBudgetEntry($ci, '2026-05-10', 2371.00);
    oneOffBudgetEntry($ci, '2026-06-10', 1492.00);
    oneOffBudgetEntry($fixed, '2026-06-12', 1337.00);

    $path = storage_path('framework/testing/one-off-budget-balances.csv');
    writeOneOffBudgetCsv($path);

    $this->artisan('app:one-off-project-budget-balances', [
        'path' => $path,
        '--strategy' => 'existing',
        '--commit' => true,
    ])->assertExitCode(0);

    $ci->refresh();
    $fixed->refresh();

    expect($ci->budget_type)->toBe(BudgetType::MonthlyCi)
        ->and((float) $ci->budget_amount)->toBe(2400.0)
        ->and($ci->budget_starts_on?->toDateString())->toBe('2026-05-01')
        ->and($ci->starts_on?->toDateString())->toBe('2026-05-01')
        ->and($ci->ends_on?->toDateString())->toBe('2027-04-30')
        ->and($fixed->budget_type)->toBe(BudgetType::FixedFee)
        ->and((float) $fixed->budget_amount)->toBe(5500.0);
});

test('existing strategy refuses to commit when current spend does not match the sheet', function () {
    $ci = Project::factory()->create(['code' => 'HOP003']);
    Project::factory()->create(['code' => 'HOP005']);
    oneOffBudgetEntry($ci, '2026-06-10', 100.00);

    $path = storage_path('framework/testing/one-off-budget-balances.csv');
    writeOneOffBudgetCsv($path);

    $this->artisan('app:one-off-project-budget-balances', [
        'path' => $path,
        '--strategy' => 'existing',
        '--commit' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Strategy existing cannot be committed');

    expect($ci->fresh()->budget_type)->toBeNull();
});

test('existing strategy refuses CI rows whose sheet allocation cannot be represented by monthly accrual', function () {
    $path = storage_path('framework/testing/one-off-den004.csv');
    file_put_contents($path, implode("\n", [
        'Job Codes,CI Client,Contracted Duration,Is it rolling?,Monthly Retainer GBP,Expected total retainer amount to end of June,Total spend to end of June',
        'DEN004,123Dentist,Sept 25 - Aug 26,"No annual",2400,28800,24105',
    ]));

    $project = Project::factory()->create(['code' => 'DEN004']);
    oneOffBudgetEntry($project, '2026-06-10', 24105.00);

    $this->artisan('app:one-off-project-budget-balances', [
        'path' => $path,
        '--strategy' => 'existing',
        '--commit' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Strategy existing cannot be committed');

    expect($project->fresh()->budget_type)->toBeNull();
});
```

- [ ] **Step 2: Run existing strategy tests**

Run:

```bash
php artisan test tests/Feature/Console/OneOffProjectBudgetBalancesTest.php --filter="existing strategy"
```

Expected: PASS if Task 1 implementation is correct.

---

### Task 3: Future Remaining-Pot Reset Strategy

**Files:**
- Modify: `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`
- Modify: `app/Console/Commands/OneOffProjectBudgetBalances.php` only if Task 1 code needs correction.

- [ ] **Step 1: Add tests for reset strategy**

Append to `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`:

```php
test('reset strategy commits future fixed-fee budgets from remaining balances', function () {
    $ci = Project::factory()->create(['code' => 'HOP003']);
    $fixed = Project::factory()->create(['code' => 'HOP005']);

    $path = storage_path('framework/testing/one-off-budget-balances.csv');
    writeOneOffBudgetCsv($path);

    $this->artisan('app:one-off-project-budget-balances', [
        'path' => $path,
        '--strategy' => 'reset',
        '--commit' => true,
    ])->assertExitCode(0);

    $ci->refresh();
    $fixed->refresh();

    expect($ci->budget_type)->toBe(BudgetType::FixedFee)
        ->and((float) $ci->budget_amount)->toBe(937.0)
        ->and($ci->budget_starts_on?->toDateString())->toBe('2026-07-01')
        ->and($ci->ends_on?->toDateString())->toBe('2027-04-30')
        ->and($fixed->budget_type)->toBe(BudgetType::FixedFee)
        ->and((float) $fixed->budget_amount)->toBe(4163.0)
        ->and($fixed->budget_starts_on?->toDateString())->toBe('2026-07-01');
});

test('reset strategy uses zero budget for projects already overspent at the snapshot', function () {
    $path = storage_path('framework/testing/one-off-overspent.csv');
    file_put_contents($path, implode("\n", [
        'Job Codes,Projects In Play,Total Project Cost,Total spend to end of June',
        'AAB003,AAB CRO Project,26000,36715',
    ]));

    $project = Project::factory()->create(['code' => 'AAB003']);

    $this->artisan('app:one-off-project-budget-balances', [
        'path' => $path,
        '--strategy' => 'reset',
        '--commit' => true,
    ])->assertExitCode(0);

    $project->refresh();

    expect($project->budget_type)->toBe(BudgetType::FixedFee)
        ->and((float) $project->budget_amount)->toBe(0.0)
        ->and($project->budget_starts_on?->toDateString())->toBe('2026-07-01');
});
```

- [ ] **Step 2: Run reset strategy tests**

Run:

```bash
php artisan test tests/Feature/Console/OneOffProjectBudgetBalancesTest.php --filter="reset strategy"
```

Expected: PASS if Task 1 implementation is correct.

---

### Task 4: Run The Real Dry-Run And Choose Strategy

**Files:**
- No code changes.

- [ ] **Step 1: Run the real report**

Run:

```bash
php artisan app:one-off-project-budget-balances "/Users/paulhalfpenny/Downloads/Harvest Transfer - CI Retainers & Active Projects to End of June 2026.xlsx" --as-of=2026-06-30
```

Expected:

- Exit 0 if every coded row maps to an Internal Tools project.
- Output one line per project with sheet spend, Internal Tools actual spend, sheet allocation, model allocation, and diffs.
- Exit 1 with `Missing project for code ...` if any project code does not exist.

- [ ] **Step 2: Try the existing-model dry-run**

Run:

```bash
php artisan app:one-off-project-budget-balances "/Users/paulhalfpenny/Downloads/Harvest Transfer - CI Retainers & Active Projects to End of June 2026.xlsx" --as-of=2026-06-30 --strategy=existing
```

Expected:

- Exit 0 only if all spend and allocation diffs are within tolerance and all CI rows have parseable contract starts.
- Likely blockers: `DEN004` and `CEP001` sheet allocations may not match simple monthly accrual exactly.

- [ ] **Step 3: Take and verify a database backup**

Run this before any command that includes `--commit`:

```bash
php artisan backup:run --only-db
php artisan backup:list
```

Expected:

- `backup:run --only-db` exits 0.
- `backup:list` shows a backup with the current date/time.
- If either command fails, stop and do not commit the budget update.

If the configured backup command is unavailable in the target environment, use the production runbook-style `mysqldump` fallback instead:

```bash
backup_path="/tmp/internal-tools-pre-budget-balances-$(date +%Y%m%d%H%M%S).sql"
mysqldump -u "$DB_USERNAME" -p "$DB_DATABASE" > "$backup_path"
ls -lh "$backup_path"
```

Expected: the dump file exists, has non-zero size, and is stored somewhere accessible for restore before proceeding.

- [ ] **Step 4: If existing passes, commit existing strategy**

Run only after reviewing the dry-run output:

```bash
php artisan app:one-off-project-budget-balances "/Users/paulhalfpenny/Downloads/Harvest Transfer - CI Retainers & Active Projects to End of June 2026.xlsx" --as-of=2026-06-30 --strategy=existing --commit
```

Expected: updates all matched projects to normal `monthly_ci` or `fixed_fee` budgets using historical actuals already in Internal Tools.

- [ ] **Step 5: If existing fails but future draw-down is enough, commit reset strategy**

Run only after reviewing the dry-run output and accepting that historical opening overspend will not be represented as a negative opening balance:

```bash
php artisan app:one-off-project-budget-balances "/Users/paulhalfpenny/Downloads/Harvest Transfer - CI Retainers & Active Projects to End of June 2026.xlsx" --as-of=2026-06-30 --strategy=reset --commit
```

Expected: updates all matched projects to `fixed_fee` budgets that begin on `2026-07-01`, with `budget_amount = max(0, sheet remaining balance)`.

- [ ] **Step 6: Spot-check reports**

Open the budget report pages for:

- `HOP003` - positive CI remaining balance.
- `MED001` - larger positive CI remaining balance.
- `FUN006` or `AAB003` - overspent at snapshot and therefore zero remaining future pot under reset strategy.

Expected: future time entries dated from `2026-07-01` draw down from the configured budget.

---

### Task 5: Verification

**Files:**
- Create: `app/Console/Commands/OneOffProjectBudgetBalances.php`
- Create: `tests/Feature/Console/OneOffProjectBudgetBalancesTest.php`

- [ ] **Step 1: Run focused tests**

Run:

```bash
php artisan test tests/Feature/Console/OneOffProjectBudgetBalancesTest.php
```

Expected: PASS.

- [ ] **Step 2: Run related budget tests**

Run:

```bash
php artisan test tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php tests/Feature/Reports/ProjectBudgetReportTest.php
```

Expected: PASS, proving the one-off command did not change budget calculator/report behaviour.

- [ ] **Step 3: Format changed PHP files**

Run:

```bash
./vendor/bin/pint app/Console/Commands/OneOffProjectBudgetBalances.php tests/Feature/Console/OneOffProjectBudgetBalancesTest.php
```

Expected: formatting completes cleanly.

- [ ] **Step 4: Review git diff**

Run:

```bash
git diff -- app/Console/Commands/OneOffProjectBudgetBalances.php tests/Feature/Console/OneOffProjectBudgetBalancesTest.php
```

Expected: only the command and focused test file changed.

- [ ] **Step 5: Commit only after approval**

Run only if the user explicitly asks for a commit:

```bash
git add app/Console/Commands/OneOffProjectBudgetBalances.php tests/Feature/Console/OneOffProjectBudgetBalancesTest.php
git commit -m "Add one-off project budget balance reconciliation command"
```

---

## Self-Review

- Scope check: This plan is one subsystem and one operational command. It avoids schema, UI, MCP, and calculator changes.
- One-off fit: The command can stay for audit/re-run purposes or be removed after the production update.
- Reconciliation safety: The `existing` strategy refuses mismatched spend/allocation instead of silently forcing the current model to fit.
- Reset safety: The `reset` strategy exactly supports future draw-down from remaining balance, but intentionally does not preserve negative opening balances in budget reports.
- Known tradeoff: If Rachel needs historical opening over/under-spend shown exactly alongside future spend, the larger opening-balance plan is still the right approach.
