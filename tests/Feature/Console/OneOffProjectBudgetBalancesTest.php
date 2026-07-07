<?php

use App\Enums\BudgetType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

function writeOneOffBudgetXlsx(string $path): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Job Codes', 'CI Client', 'Contracted Duration', 'Is it rolling?', 'Monthly Retainer GBP', 'Expected total retainer amount to end of June', 'Total spend to end of June'],
        ['HOP003', 'Homeprotect', 'May 26 - April 27', 'Yes, year on year', 2400, 4800, 3863],
        [],
        ['Job Codes', 'Projects In Play', 'Total Project Cost', 'Total spend to end of June'],
        ['HOP005', 'HP WebMCP', 5500, 1337],
    ]);

    (new Xlsx($spreadsheet))->save($path);
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

test('one off budget balance command reads xlsx exports', function () {
    $ci = Project::factory()->create(['code' => 'HOP003']);
    $fixed = Project::factory()->create(['code' => 'HOP005']);
    oneOffBudgetEntry($ci, '2026-05-10', 2371.00);
    oneOffBudgetEntry($ci, '2026-06-10', 1492.00);
    oneOffBudgetEntry($fixed, '2026-06-12', 1337.00);

    $path = storage_path('framework/testing/one-off-budget-balances.xlsx');
    writeOneOffBudgetXlsx($path);

    $this->artisan('app:one-off-project-budget-balances', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('HOP003')
        ->expectsOutputToContain('HOP005')
        ->expectsOutputToContain('matched 2 row(s)');
});

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

test('reset strategy does not clear an existing fixed project end date', function () {
    $path = storage_path('framework/testing/one-off-fixed.csv');
    file_put_contents($path, implode("\n", [
        'Job Codes,Projects In Play,Total Project Cost,Total spend to end of June',
        'HOP005,HP WebMCP,5500,1337',
    ]));

    $project = Project::factory()->create([
        'code' => 'HOP005',
        'ends_on' => '2026-12-31',
    ]);

    $this->artisan('app:one-off-project-budget-balances', [
        'path' => $path,
        '--strategy' => 'reset',
        '--commit' => true,
    ])->assertExitCode(0);

    expect($project->fresh()->ends_on?->toDateString())->toBe('2026-12-31');
});
