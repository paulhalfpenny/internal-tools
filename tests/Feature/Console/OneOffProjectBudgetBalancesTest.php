<?php

use App\Enums\BudgetType;
use App\Models\Project;
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
