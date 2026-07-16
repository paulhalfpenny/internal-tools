# Budget Report Filtering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add month / date-range / task / user filtering to the project budget report's time-entries list, plus a filtered-totals summary, without touching the budget stat cards or monthly breakdown.

**Architecture:** Wire filter state (component-only, no URL) into the `ProjectBudget` Livewire component. The existing `App\Domain\Reporting\TimeReportQuery` already accepts `from`/`to`/`userId`/`taskId` and already exposes `totals()`, so this is mostly wiring + a Blade filter bar mirroring the `Admin\TimeEntries\BulkMove` pattern. The budget cards and monthly breakdown are computed separately (`ProjectBudgetCalculator`) and are deliberately left untouched.

**Tech Stack:** Laravel 11, Livewire 3, Pest 3, Tailwind (Blade). SQLite in tests. Runners: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan`.

**Spec:** `docs/superpowers/specs/2026-07-16-budget-report-filtering-design.md`

---

## File Structure

- **Modify** `app/Livewire/Reports/ProjectBudget.php` — add filter props, lifecycle hooks, `filteredWindow()`, `clearFilters()`, and render wiring (window + `userId`/`taskId` into `TimeReportQuery`, plus `totals()` and dropdown option lists into the view).
- **Modify** `resources/views/livewire/reports/project-budget.blade.php` — add the filter bar + filtered-totals summary directly above the existing "Time entries" table.
- **Create** `tests/Feature/Reports/ProjectBudgetFilterTest.php` — Pest feature tests driving the component.

No changes to `TimeReportQuery`, `TimeEntry`, `ProjectBudgetCalculator`, routes, or migrations — all required capability already exists.

**Out of scope (do not implement):** URL/`#[Url]` state; filtering the CSV `export()` (it stays whole-lifetime); client filtering (page is already single-project); any change to budget-calculation logic.

---

## Task 0: Branch

- [ ] **Step 1: Create a feature branch**

This repo works on branches directly in the main checkout (no worktrees).

Run:
```bash
git checkout -b feature/budget-report-filters
```

---

## Task 1: Filter state & query wiring in the component

**Files:**
- Modify: `app/Livewire/Reports/ProjectBudget.php`
- Test: `tests/Feature/Reports/ProjectBudgetFilterTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Reports/ProjectBudgetFilterTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Reports/ProjectBudgetFilterTest.php`
Expected: FAIL — the component has no `filterUserId`/`filterTaskId`/`filterMonth`/`filterFrom`/`filterTo` properties or `clearFilters()` method, so Livewire errors on the `set`/`call`.

- [ ] **Step 3: Add filter props, hooks, and window resolution to the component**

In `app/Livewire/Reports/ProjectBudget.php`, add `use App\Models\Task;` to the imports (alongside the existing `App\Models\Project`, `App\Models\TimeEntry`, `App\Models\User`).

Then add the filter properties and methods to the class body (place them after the `mount()` method, before `export()`):

```php
    public ?string $filterMonth = null;

    public ?string $filterFrom = null;

    public ?string $filterTo = null;

    public ?int $filterTaskId = null;

    public ?int $filterUserId = null;

    public function updating(string $name, mixed $value): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->resetPage();
        }
    }

    public function updatedFilterMonth(): void
    {
        // Month and custom range are mutually exclusive.
        $this->filterFrom = null;
        $this->filterTo = null;
    }

    public function updatedFilterFrom(): void
    {
        $this->filterMonth = null;
    }

    public function updatedFilterTo(): void
    {
        $this->filterMonth = null;
    }

    public function clearFilters(): void
    {
        $this->reset(['filterMonth', 'filterFrom', 'filterTo', 'filterTaskId', 'filterUserId']);
        $this->resetPage();
    }

    /**
     * The date window the entries list & totals should cover, honouring the
     * active filters. Month wins over custom range; custom range wins over the
     * default lifetime window; each custom bound is optional.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function filteredWindow(): array
    {
        if (filled($this->filterMonth)) {
            $month = CarbonImmutable::parse($this->filterMonth.'-01');

            return [$month->startOfMonth(), $month->endOfMonth()];
        }

        if (filled($this->filterFrom) || filled($this->filterTo)) {
            [$lifeFrom, $lifeTo] = $this->lifetimeWindow();
            $from = filled($this->filterFrom) ? CarbonImmutable::parse($this->filterFrom) : $lifeFrom;
            $to = filled($this->filterTo) ? CarbonImmutable::parse($this->filterTo)->endOfDay() : $lifeTo;

            return [$from, $to];
        }

        return $this->lifetimeWindow();
    }
```

- [ ] **Step 4: Rewrite `render()` to apply filters and expose options + totals**

Replace the existing `render()` method body in `app/Livewire/Reports/ProjectBudget.php` with:

```php
    public function render(ProjectBudgetCalculator $calculator): View
    {
        [$from, $to] = $this->filteredWindow();

        $query = new TimeReportQuery(
            from: $from,
            to: $to,
            userId: $this->filterUserId,
            projectId: $this->project->id,
            taskId: $this->filterTaskId,
        );

        $entries = $query->paginate();
        $filteredTotals = $query->totals();

        $hasFilters = filled($this->filterMonth)
            || filled($this->filterFrom)
            || filled($this->filterTo)
            || $this->filterUserId !== null
            || $this->filterTaskId !== null;

        $projectId = $this->project->id;

        $monthOptions = TimeEntry::query()
            ->where('project_id', $projectId)
            ->orderByDesc('spent_on')
            ->pluck('spent_on')
            ->map(fn ($date) => $date->format('Y-m'))
            ->unique()
            ->values()
            ->map(fn (string $ym) => [
                'value' => $ym,
                'label' => CarbonImmutable::parse($ym.'-01')->format('F Y'),
            ]);

        $taskOptions = Task::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $projectId)->select('task_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $userOptions = User::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $projectId)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        /** @var User|null $user */
        $user = auth()->user();

        return view('livewire.reports.project-budget', [
            'status' => $calculator->forProject($this->project),
            'monthlyRows' => $calculator->monthlyBreakdown($this->project),
            'entries' => $entries,
            'filteredTotals' => $filteredTotals,
            'hasFilters' => $hasFilters,
            'monthOptions' => $monthOptions,
            'taskOptions' => $taskOptions,
            'userOptions' => $userOptions,
            'hoursFormat' => $user?->hoursDisplayFormat() ?? HoursFormatter::FORMAT_HHMM,
        ]);
    }
```

Note: the Blade view does not reference the new variables yet — that is Task 2. Passing them now is harmless and lets Task 1's behaviour tests pass against the real query wiring.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Reports/ProjectBudgetFilterTest.php`
Expected: PASS (all 8 tests).

- [ ] **Step 6: Run the existing budget report tests to confirm no regression**

Run: `./vendor/bin/pest tests/Feature/Reports/`
Expected: PASS (existing `ProjectBudgetReportTest`, `ProjectBudgetExportTest`, `ScopedCsvExportTest` still green).

- [ ] **Step 7: Lint & static analysis**

Run:
```bash
./vendor/bin/pint app/Livewire/Reports/ProjectBudget.php tests/Feature/Reports/ProjectBudgetFilterTest.php
./vendor/bin/phpstan analyse app/Livewire/Reports/ProjectBudget.php
```
Expected: Pint reports no style issues (or fixes them); PHPStan reports no errors.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Reports/ProjectBudget.php tests/Feature/Reports/ProjectBudgetFilterTest.php
git commit -m "feat: filter budget report entries by month, date, task and user"
```

---

## Task 2: Filter bar + filtered-totals summary in the view

**Files:**
- Modify: `resources/views/livewire/reports/project-budget.blade.php`
- Test: `tests/Feature/Reports/ProjectBudgetFilterTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Reports/ProjectBudgetFilterTest.php` (the fixture logs April & May 2026 entries, so `May 2026` is a valid month-option label):

```php
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
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Reports/ProjectBudgetFilterTest.php --filter="filter bar renders|filtered-totals summary"`
Expected: FAIL — the Blade view has no filter controls or summary yet (`assertSeeHtml` / `wire:click="clearFilters"` not found).

- [ ] **Step 3: Add the filter bar + summary to the Blade view**

In `resources/views/livewire/reports/project-budget.blade.php`, find the "Time entries" heading (currently line 131):

```blade
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 mt-6">Time entries</h2>
        <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
```

Insert the following block **between** that `<h2>` and the `<div class="bg-white ...">` that opens the entries table:

```blade
        {{-- Entries filters --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4 mb-3">
            <div class="grid grid-cols-5 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Month</label>
                    <select wire:model.live="filterMonth" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                        <option value="">All months</option>
                        @foreach($monthOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                    <input wire:model.live="filterFrom" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input wire:model.live="filterTo" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Task</label>
                    <select wire:model.live="filterTaskId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                        <option value="">All tasks</option>
                        @foreach($taskOptions as $task)
                            <option value="{{ $task->id }}">{{ $task->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">User</label>
                    <select wire:model.live="filterUserId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                        <option value="">All users</option>
                        @foreach($userOptions as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if($hasFilters)
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                    <div class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">{{ $entries->total() }}</span> entries
                        · <span class="font-medium text-gray-900">{{ HoursFormatter::format((float) $filteredTotals->totalHours, $hoursFormat) }}</span> hrs
                        · <span class="font-medium text-gray-900">£{{ number_format($filteredTotals->billableAmount, 2) }}</span>
                    </div>
                    <button wire:click="clearFilters" class="text-sm text-gray-500 hover:text-gray-700">Clear filters</button>
                </div>
            @endif
        </div>
```

(`HoursFormatter` is already imported at the top of the view via `@use('App\Domain\TimeTracking\HoursFormatter')`, and `$hoursFormat` is already passed in.)

- [ ] **Step 4: Run the new tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Reports/ProjectBudgetFilterTest.php`
Expected: PASS (all tests, including the two new view tests).

- [ ] **Step 5: Full reports suite regression check**

Run: `./vendor/bin/pest tests/Feature/Reports/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/reports/project-budget.blade.php tests/Feature/Reports/ProjectBudgetFilterTest.php
git commit -m "feat: add filter bar and filtered-totals summary to budget report view"
```

---

## Task 3: Verification & local test handoff

- [ ] **Step 1: Run the full test suite**

Run: `./vendor/bin/pest`
Expected: PASS (no regressions anywhere).

- [ ] **Step 2: Lint & static analysis across the changes**

Run:
```bash
./vendor/bin/pint
./vendor/bin/phpstan analyse
```
Expected: no style issues, no PHPStan errors.

- [ ] **Step 3: Drive the change in the real app (the "test it yourself" handoff — FOR THE USER)**

Herd serves the app at `https://internal-tools.test`. Log the user in via the demo-login route, then open a budget report so they can drive the filters themselves:

1. Confirm the dev environment is up (Herd is always-on; no `artisan serve` needed).
2. Open `https://internal-tools.test/demo-login` in Chrome to land authenticated as a demo user.
3. Navigate to a project budget report with real data, e.g. `https://internal-tools.test/reports/projects/140/budget`.
4. Ask the user to verify:
   - Picking a **Month** narrows the entries list and shows the filtered totals line (entries · hrs · £).
   - Setting a custom **From/To** clears the Month selection and narrows the list.
   - **Task** and **User** dropdowns list only values present on this project, and combine with the month filter (the "how much dev did X do in June" case).
   - **Clear filters** resets everything.
   - The **budget stat cards** and **monthly breakdown** table are unchanged as filters change.

---

## Self-Review

**Spec coverage:**
- Filter by budget month → `filterMonth` prop + `filteredWindow()` month branch + Month dropdown (Task 1 Step 3–4, Task 2 Step 3). ✔
- Filter by time-entry date → `filterFrom`/`filterTo` + custom-range branch + From/To inputs. ✔
- Filter by task (PM/Development = task name) → `filterTaskId` into `TimeReportQuery` + Task dropdown scoped to project. ✔
- Filter by user → `filterUserId` into `TimeReportQuery` + User dropdown scoped to project. ✔
- Combine month + task (+ user) → covered by the combined-filter test (Task 1). ✔
- Filtered aggregate total → `TimeReportQuery::totals()` + summary line (Task 2). ✔
- Scope guardrail (cards/breakdown untouched) → `status`/`monthlyRows` still from `ProjectBudgetCalculator`, asserted by "filters do not change budget cards" test. ✔
- No URL state → props are plain public props, no `#[Url]`. ✔
- Month↔range mutual exclusion → `updatedFilterMonth/From/To` hooks + test. ✔
- Local test handoff → Task 3 Step 3. ✔

**Placeholder scan:** No TBD/TODO. Every code step shows complete code. Task 2 Step 1 intentionally contains a deliberately-wrong assertion that Step 1a corrects — the executor writes the corrected version from Step 1a. ✔

**Type consistency:** `filteredWindow()` returns `array{0: CarbonImmutable, 1: CarbonImmutable}` matching `lifetimeWindow()`; `TimeReportQuery` constructor arg order/names (`from`, `to`, `userId`, `projectId`, `taskId`) match `app/Domain/Reporting/TimeReportQuery.php`; `TotalsDto->totalHours`/`->billableAmount` match `app/Domain/Reporting/TotalsDto.php`; `clearFilters()` name matches its test and the `wire:click`. ✔
