<?php

use App\Enums\BudgetType;
use App\Enums\Role;
use App\Livewire\Reports\ProjectBudget;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A project with two users, two tasks, and entries spanning April & May 2026.
 * Every entry is billable at £100 so budget-card totals are easy to reason about.
 *
 * @return array{project: Project, alice: User, bob: User, dev: Task, pm: Task}
 */
function budgetFilterFixture(): array
{
    $project = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 5000.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    $alice = User::factory()->create(['name' => 'Alice Example']);
    $bob = User::factory()->create(['name' => 'Bob Sample']);
    $dev = Task::factory()->create(['name' => 'Development']);
    $pm = Task::factory()->create(['name' => 'PM']);

    $make = fn (User $u, Task $t, string $date, string $notes) => TimeEntry::factory()->create([
        'project_id' => $project->id,
        'user_id' => $u->id,
        'task_id' => $t->id,
        'spent_on' => $date,
        'hours' => 2.0,
        'is_billable' => true,
        'billable_amount' => 100.0,
        'notes' => $notes,
    ]);

    $make($alice, $dev, '2026-04-10', 'April dev Alice');
    $make($alice, $pm, '2026-04-12', 'April PM Alice');
    $make($bob, $dev, '2026-05-10', 'May dev Bob');
    $make($bob, $pm, '2026-05-12', 'May PM Bob');

    return compact('project', 'alice', 'bob', 'dev', 'pm');
}

test('filtering by user narrows the entries list to that user', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'alice' => $alice] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterUserId', $alice->id)
        ->assertSee('April dev Alice')
        ->assertDontSee('May dev Bob');
});

test('filtering by task narrows the entries list to that task', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'dev' => $dev] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterTaskId', $dev->id)
        ->assertSee('April dev Alice')
        ->assertSee('May dev Bob')
        ->assertDontSee('April PM Alice');
});

test('filtering by month narrows the entries list to that month', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterMonth', '2026-05')
        ->assertSee('May dev Bob')
        ->assertDontSee('April dev Alice');
});

test('month and task filters combine (the FLTR-2423 use case)', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'bob' => $bob, 'dev' => $dev] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterMonth', '2026-05')
        ->set('filterUserId', $bob->id)
        ->set('filterTaskId', $dev->id)
        ->assertSee('May dev Bob')
        ->assertDontSee('May PM Bob')
        ->assertDontSee('April dev Alice');
});

test('a custom date range narrows the entries list', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterFrom', '2026-05-01')
        ->set('filterTo', '2026-05-31')
        ->assertSee('May dev Bob')
        ->assertDontSee('April dev Alice');
});

test('choosing a month clears a previously set custom range and vice versa', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterFrom', '2026-04-01')
        ->set('filterTo', '2026-04-30')
        ->set('filterMonth', '2026-05')
        ->assertSet('filterFrom', null)
        ->assertSet('filterTo', null)
        ->set('filterFrom', '2026-04-01')
        ->assertSet('filterMonth', null);
});

test('clearFilters resets every filter', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'alice' => $alice, 'dev' => $dev] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterMonth', '2026-04')
        ->set('filterUserId', $alice->id)
        ->set('filterTaskId', $dev->id)
        ->call('clearFilters')
        ->assertSet('filterMonth', null)
        ->assertSet('filterFrom', null)
        ->assertSet('filterTo', null)
        ->assertSet('filterUserId', null)
        ->assertSet('filterTaskId', null);
});

test('filters do not change the whole-project budget cards', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'alice' => $alice] = budgetFilterFixture();

    $this->actingAs($admin);

    // 4 billable entries * £100 = £400 cumulative spent, whole-project.
    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterUserId', $alice->id)
        ->assertSee('£400.00'); // Cumulative spent card is unaffected by the user filter.
});

test('invalid filter values fall back to the full window instead of crashing', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterMonth', 'not-a-month')
        ->assertOk()
        ->assertSee('April dev Alice')
        ->assertSee('May dev Bob')
        ->set('filterMonth', null)
        ->set('filterFrom', 'garbage')
        ->assertOk()
        ->assertSee('April dev Alice')
        ->assertSee('May dev Bob');
});

test('the entries filter bar renders its controls', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->assertSeeHtml('wire:model.live="filterMonth"')
        ->assertSeeHtml('wire:model.live="filterTaskId"')
        ->assertSeeHtml('wire:model.live="filterUserId"')
        ->assertSeeHtml('wire:model.live="filterFrom"')
        ->assertSeeHtml('wire:model.live="filterTo"')
        ->assertSee('May 2026');
});

test('the filtered-totals summary appears only when a filter is active', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'alice' => $alice] = budgetFilterFixture();

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->assertDontSeeHtml('wire:click="clearFilters"')
        ->set('filterUserId', $alice->id)
        // Alice has 2 entries * 2h = 4h, 2 * £100 = £200.
        ->assertSeeHtml('wire:click="clearFilters"')
        ->assertSee('£200.00');
});

test('resetting a dropdown to the empty option restores all entries', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project, 'dev' => $dev] = budgetFilterFixture();

    $this->actingAs($admin);

    // Setting a select back to its "" option is the real per-keystroke user
    // path (distinct from clearFilters()); Livewire resets the ?int prop to null.
    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterTaskId', $dev->id)
        ->assertDontSee('April PM Alice')
        ->set('filterTaskId', '')
        ->assertSet('filterTaskId', null)
        ->assertSee('April PM Alice')
        ->assertSee('May PM Bob');
});

test('an open-ended custom range uses the lifetime bound for the missing end', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    // Only a "from" bound: "to" falls back to the lifetime window (today),
    // so every entry on/after the from date is included.
    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterFrom', '2026-05-01')
        ->assertSee('May dev Bob')
        ->assertDontSee('April dev Alice');

    // Only a "to" bound: "from" falls back to the lifetime start.
    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterTo', '2026-04-30')
        ->assertSee('April dev Alice')
        ->assertDontSee('May dev Bob');
});

test('the empty-entries message is filter-aware', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    ['project' => $project] = budgetFilterFixture();

    $this->actingAs($admin);

    // A month with no entries yields an empty, filtered result.
    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->set('filterMonth', '2026-07')
        ->assertSee('No time entries match the current filters.')
        ->assertDontSee('No time entries in this window yet.');
});
