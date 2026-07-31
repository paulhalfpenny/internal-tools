# Neutral Project Detail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every Projects report row open a neutral project-detail page, including a spend-only experience for projects without a configured budget.

**Architecture:** Rename the existing `ProjectBudget` Livewire component to `ProjectDetail` and retain its shared project, export, filtering, and time-entry flow. Extend the budget calculator with a neutral monthly-spend series, then conditionally render either the existing budget analysis or new spend-only summary/table from the single detail view. Add the neutral route as canonical and preserve the legacy budget URL as a redirect.

**Tech Stack:** Laravel 11, Livewire 3, Blade, Pest, Carbon.

## Global Constraints

- Canonical detail URL is `/reports/projects/{project}`, named `reports.projects.detail`.
- Keep `/reports/projects/{project}/budget` as a redirect for existing bookmarks and notifications.
- Do not change budget calculations or the scope of time-entry filters/export.
- No budget, variance, or percentage-used content appears for no-budget projects.
- Do not create commits; the current user request does not authorise one.

---

## File Structure

- Rename `app/Livewire/Reports/ProjectBudget.php` to `app/Livewire/Reports/ProjectDetail.php` — shared detail-page component, filters, export and data assembly.
- Rename `resources/views/livewire/reports/project-budget.blade.php` to `resources/views/livewire/reports/project-detail.blade.php` — shared shell and conditional budget/spend-only analysis sections.
- Modify `app/Domain/Budgeting/ProjectBudgetCalculator.php` — provide a neutral monthly-spend series and preserve the budget-enriched breakdown.
- Modify `app/Livewire/Reports/ProjectsReport.php` and `resources/views/livewire/reports/projects-report.blade.php` — link every reported project to the canonical detail route.
- Modify `resources/views/livewire/reports/client-detail.blade.php`, `app/Notifications/BudgetThresholdReached.php`, and `routes/web.php` — move callers to the canonical name while retaining the legacy redirect.
- Update `tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php`, `tests/Feature/Reports/ProjectBudgetReportTest.php`, `tests/Feature/Reports/ProjectBudgetFilterTest.php`, and `tests/Feature/Reports/ProjectBudgetExportTest.php` — cover the renamed component, spend-only rendering, links, and compatibility redirect.

### Task 1: Define the neutral monthly-spend API

**Files:**
- Modify: `tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php`
- Modify: `app/Domain/Budgeting/ProjectBudgetCalculator.php`

**Interfaces:**
- Produces `ProjectBudgetCalculator::monthlySpend(Project $project, ?CarbonImmutable $asOf = null): Collection<int, stdClass>`.
- Each row has `month`, `month_label`, `month_amount`, `month_hours`, `running_amount`, and `running_hours`.
- `monthlyBreakdown()` keeps its existing budget fields for budgeted projects by enriching the neutral spend rows.

- [ ] **Step 1: Write the failing calculator test**

```php
test('monthly spend returns billable per-month and cumulative totals without a budget', function () {
    $project = Project::factory()->create(['budget_type' => null, 'starts_on' => null]);
    // Seed April billable (400, 4h), May billable (600, 6h), and May non-billable (0, 2h).

    $rows = (new ProjectBudgetCalculator)->monthlySpend($project, CarbonImmutable::parse('2026-05-31'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('month_amount')->all())->toBe([400.0, 600.0])
        ->and($rows->pluck('running_amount')->all())->toBe([400.0, 1000.0])
        ->and($rows->pluck('running_hours')->all())->toBe([4.0, 10.0]);
});
```

- [ ] **Step 2: Run the focused test and confirm the expected missing-method failure**

Run: `php artisan test tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php --filter=monthly-spend`

Expected: FAIL because `monthlySpend()` does not exist.

- [ ] **Step 3: Add the minimal neutral monthly-spend calculation**

```php
public function monthlySpend(Project $project, ?CarbonImmutable $asOf = null): Collection
{
    // Resolve project start or earliest entry; return collect() with neither.
    // Group billable entries by month and emit zero-filled months through $asOf.
    // Carry running amount and hours forward on every row.
}
```

Use `budget_starts_on`, then `starts_on`, then the project's earliest time-entry date for the first month. Use only billable entries, matching `forProject()` and the current budget breakdown.

- [ ] **Step 4: Refactor `monthlyBreakdown()` to enrich the common spend rows**

```php
return $this->monthlySpend($project, $asOf)->map(function (stdClass $row, int $index) use ($project) {
    $monthBudget = $project->budget_type === BudgetType::FixedFee
        ? ($index === 0 ? (float) $project->budget_amount : 0.0)
        : (float) $project->budget_amount;

    // Add month_budget, running_budget and running_variance without changing spend values.
});
```

- [ ] **Step 5: Run calculator coverage**

Run: `php artisan test tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php`

Expected: PASS, including the new spend-only case and all existing budget cases.

### Task 2: Establish the neutral route and component contract

**Files:**
- Modify: `tests/Feature/Reports/ProjectBudgetReportTest.php`
- Rename: `app/Livewire/Reports/ProjectBudget.php` to `app/Livewire/Reports/ProjectDetail.php`
- Rename: `resources/views/livewire/reports/project-budget.blade.php` to `resources/views/livewire/reports/project-detail.blade.php`
- Modify: `routes/web.php`

**Interfaces:**
- `App\Livewire\Reports\ProjectDetail` replaces `ProjectBudget` as the Livewire class.
- `reports.projects.detail` resolves `GET /reports/projects/{project}`.
- `reports.projects.budget` resolves to a redirect response whose `Location` is `reports.projects.detail`.

- [ ] **Step 1: Write failing route and component tests**

```php
use App\Livewire\Reports\ProjectDetail;

test('the neutral project detail route renders for an unbudgeted project', function () {
    $this->actingAs($admin)
        ->get(route('reports.projects.detail', $project))
        ->assertOk();
});

test('the legacy budget route redirects to neutral project detail', function () {
    $this->actingAs($admin)
        ->get(route('reports.projects.budget', $project))
        ->assertRedirect(route('reports.projects.detail', $project));
});
```

- [ ] **Step 2: Run the focused route tests and confirm they fail because the new route/component do not exist**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetReportTest.php --filter='neutral|legacy'`

Expected: FAIL with missing route or missing class errors.

- [ ] **Step 3: Rename the component and view, then wire the canonical/legacy routes**

Use `git mv` for both rename operations. Rename the class to `ProjectDetail`, update the component view name to `livewire.reports.project-detail`, add the canonical route before the legacy route, and use `Route::redirect()` for the legacy path while retaining its route name.

- [ ] **Step 4: Update existing component test imports/references**

```php
use App\Livewire\Reports\ProjectDetail;

Livewire::test(ProjectDetail::class, ['project' => $project]);
```

- [ ] **Step 5: Run focused route/component tests**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetReportTest.php`

Expected: PASS for the canonical route, legacy redirect, and existing budgeted detail behaviour.

### Task 3: Render the spend-only detail state

**Files:**
- Modify: `tests/Feature/Reports/ProjectBudgetReportTest.php`
- Modify: `app/Livewire/Reports/ProjectDetail.php`
- Modify: `resources/views/livewire/reports/project-detail.blade.php`

**Interfaces:**
- The component passes `monthlySpend` for every project and `monthlyRows` for budgeted projects.
- The view renders spend-only content when `$status === null` and budget content when `$status !== null`.

- [ ] **Step 1: Write the failing spend-only rendering test**

```php
Livewire::test(ProjectDetail::class, ['project' => $project])
    ->assertSee('This month spent')
    ->assertSee('Cumulative spent')
    ->assertSee('April 2026')
    ->assertSee('Cumulative spent (£)')
    ->assertDontSee('Cumulative budget')
    ->assertDontSee('Variance')
    ->assertDontSee('Set a budget');
```

Seed an unbudgeted project with billable entries across two months and a time entry belonging to another project. Assert the page shows only its own entries.

- [ ] **Step 2: Run the focused test and confirm the current no-budget notice makes it fail**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetReportTest.php --filter=spend-only`

Expected: FAIL because the page renders the budget-configuration notice rather than spend analysis.

- [ ] **Step 3: Assemble monthly data for both project types**

```php
'status' => $calculator->forProject($this->project),
'monthlySpend' => $calculator->monthlySpend($this->project),
'monthlyRows' => $this->project->budget_type === null
    ? collect()
    : $calculator->monthlyBreakdown($this->project),
```

- [ ] **Step 4: Replace the no-budget notice with the spend-only summary and table**

Render two cards (this-month spent/hours and cumulative spent/hours) and a monthly table with Month, Spent (£), Spent (hrs), Cumulative spent (£), and Cumulative hrs. Reuse the existing current-month highlighting and `HoursFormatter`; keep budget/variance markup inside the budgeted branch only. Move the shared time-entry filter/table markup after the conditional analysis so it renders for both branches.

- [ ] **Step 5: Run the spend-only and budgeted-detail tests**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetReportTest.php`

Expected: PASS, including spend-only assertions and existing budgeted content checks.

### Task 4: Link every report surface to the canonical detail page

**Files:**
- Modify: `tests/Feature/Reports/ProjectBudgetReportTest.php`
- Modify: `resources/views/livewire/reports/projects-report.blade.php`
- Modify: `resources/views/livewire/reports/client-detail.blade.php`
- Modify: `app/Notifications/BudgetThresholdReached.php`

**Interfaces:**
- All newly-rendered project links use `route('reports.projects.detail', $projectOrId)`.
- `reports.projects.budget` remains compatibility-only.

- [ ] **Step 1: Write a failing report-link test**

```php
Livewire::test(ProjectsReport::class)
    ->assertSeeHtml('href="'.route('reports.projects.detail', $budgeted).'"')
    ->assertSeeHtml('href="'.route('reports.projects.detail', $unbudgeted).'"');
```

Seed report-period time for both projects. The second assertion must fail before the template change because unbudgeted names are plain text.

- [ ] **Step 2: Run the focused link test and confirm the unbudgeted-project assertion fails**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetReportTest.php --filter='report.*link|links.*report'`

Expected: FAIL because no-budget projects do not currently produce anchors.

- [ ] **Step 3: Render an anchor unconditionally in the Projects and Client detail reports**

```blade
<a href="{{ route('reports.projects.detail', $row->id) }}" class="text-blue-700 hover:underline">
    {{ $row->label }}
</a>
```

Replace the current `$b` conditional only for the project name. Leave all budget-number display logic unchanged.

- [ ] **Step 4: Update notification links to the canonical route**

```php
'projectBudgetUrl' => route('reports.projects.detail', $project),
```

Keep the existing data key so downstream notification templates do not change as part of this feature.

- [ ] **Step 5: Run report and notification coverage**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetReportTest.php tests/Feature/Reports/ProjectBudgetExportTest.php`

Expected: PASS.

### Task 5: Preserve filters/export and run the final QA suite

**Files:**
- Modify: `tests/Feature/Reports/ProjectBudgetFilterTest.php`
- Modify: `tests/Feature/Reports/ProjectBudgetExportTest.php`

**Interfaces:**
- Filter and export tests mount `ProjectDetail::class`.
- The CSV still covers the project lifetime and remains project-scoped.

- [ ] **Step 1: Update tests to the renamed component without changing their behavioural assertions**

```php
use App\Livewire\Reports\ProjectDetail;

Livewire::test(ProjectDetail::class, ['project' => $project]);
```

- [ ] **Step 2: Run filtering/export tests and correct only rename-related failures**

Run: `php artisan test tests/Feature/Reports/ProjectBudgetFilterTest.php tests/Feature/Reports/ProjectBudgetExportTest.php`

Expected: PASS.

- [ ] **Step 3: Run the full relevant regression suite**

Run: `php artisan test tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php tests/Feature/Reports/ProjectBudgetReportTest.php tests/Feature/Reports/ProjectBudgetFilterTest.php tests/Feature/Reports/ProjectBudgetExportTest.php`

Expected: PASS with zero failures.

- [ ] **Step 4: Run static checks and inspect the diff**

Run: `vendor/bin/pint --dirty && php artisan test tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php tests/Feature/Reports/ProjectBudgetReportTest.php tests/Feature/Reports/ProjectBudgetFilterTest.php tests/Feature/Reports/ProjectBudgetExportTest.php && git diff --check && git diff --stat`

Expected: formatter exits successfully; all focused tests pass; diff check is clean.

- [ ] **Step 5: Perform browser QA locally**

Open the local app, sign in through `/demo-login`, and verify:

1. A budgeted project opens `/reports/projects/{project}` and retains budget/variance analysis.
2. An unbudgeted project opens the same neutral URL and shows only spend/cumulative analysis plus its time entries.
3. A legacy `/reports/projects/{project}/budget` URL redirects to the neutral page.
4. Month/task/user filters, pagination, and CSV export still operate from both states.
