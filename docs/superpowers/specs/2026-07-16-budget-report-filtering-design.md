# Filtering on the Project Budget report — Design

**Source:** Asana FLTR-2423 — "Feature Request: Filtering Ability on Budget Reports"
(https://app.asana.com/1/155579732034488/project/1216112855566139/task/1216559353815506)

**Date:** 2026-07-16
**Page affected:** `/reports/projects/{project}/budget` (route `reports.projects.budget`)

## Problem

On the project budget report a user cannot easily answer questions like *"how much
dev time was spent for 123 Dentist in June by Mark Manders?"* — the time-entries list
is unfiltered, so they resort to adding entries up by hand. The request asks for the
ability to filter by budget month, time-entry date, task type (e.g. PM vs Development),
and user, and to combine those filters (month + task especially).

## Scope guardrail

Filters affect **only** the time-entries list plus a **new filtered-totals summary**.

The budget stat cards and the monthly breakdown table stay whole-project and are **not**
touched by the filters — "budget" is a fixed whole-project figure and filtering it by
user/task would be semantically misleading. The filtered-totals summary is what answers
the aggregate question ("how much dev time did X spend in June") with a single number.

Out of scope: URL/shareable filter state (no `#[Url]`); client filtering (the page is
already scoped to one project); changing budget calculation logic.

## Existing building blocks (reused, not rebuilt)

- **`App\Livewire\Reports\ProjectBudget`** (`app/Livewire/Reports/ProjectBudget.php`) —
  the component. Already `use WithPagination`. Currently passes only `projectId` + a
  lifetime date window into the entries query.
- **`App\Domain\Reporting\TimeReportQuery`** (`app/Domain/Reporting/TimeReportQuery.php`) —
  already accepts `from`, `to`, `projectId`, `userId`, `taskId` and applies them in
  `baseQuery()`. Adding user/task/date filtering is mostly wiring these through.
- **`App\Livewire\Admin\TimeEntries\BulkMove`** (`app/Livewire/Admin/TimeEntries/BulkMove.php`
  + its Blade view) — the UI pattern to mirror: labelled `<select wire:model.live="filterXxxId">`
  dropdowns, nullable `filter*` props, and an `updating()` hook calling `resetPage()`.
- Time-entry data lives in the local `time_entries` table (Harvest CSV import + Asana
  sync), queried via the `TimeEntry` Eloquent model. Backing columns: `spent_on` (date),
  `task_id` → `tasks.name`, `user_id` → `users.name`, `hours`, `billable_amount`.

## Design

### 1. Component: filter state

Add nullable public props to `ProjectBudget` (component state only — no `#[Url]`):

- `?string $filterMonth` — `Y-m` value from the month dropdown (e.g. `"2026-06"`)
- `?string $filterFrom` / `?string $filterTo` — custom date range (`Y-m-d`)
- `?int $filterTaskId`
- `?int $filterUserId`

### 2. Window precedence

Month and custom range are mutually exclusive to keep behaviour unambiguous:

1. `filterMonth` set → window = first day → last day of that month.
2. else if `filterFrom` and/or `filterTo` set → use them (either bound optional).
3. else → the existing lifetime window (project/budget start → today).

Livewire hooks enforce mutual exclusivity and reset pagination:

- `updatedFilterMonth()` → clear `filterFrom`/`filterTo`.
- `updatedFilterFrom()` / `updatedFilterTo()` → clear `filterMonth`.
- `updating($name)` → if `$name` starts with `filter`, `resetPage()` (mirrors `BulkMove`).

The resolved `from`/`to`, plus `filterUserId` and `filterTaskId`, are passed into
`TimeReportQuery` for the entries list.

### 3. Filtered-totals summary

Add a `totals()` method to `TimeReportQuery` that reuses `baseQuery()` and returns the
aggregate over the *filtered* set (before pagination):

- `hours` — `SUM(hours)`
- `amount` — `SUM(billable_amount)`
- `count` — row count

The component exposes these to the view. The view shows a one-line summary above the
entries table (e.g. *"14 entries · 22.5 hrs · £2,250"*) whenever any filter is active.

### 4. Dropdown options — scoped to the project

Options are limited to values that actually appear on this project's entries, so the
lists stay short and relevant:

- **Months**: distinct `spent_on` months for `project_id`, newest first, labelled
  `"June 2026"`, value `"2026-06"`.
- **Tasks**: `Task` records whose `id` appears in this project's `time_entries.task_id`
  (this is where "PM"/"Development" surface — task *name* is the type), ordered by name.
- **Users**: `User` records whose `id` appears in this project's `time_entries.user_id`,
  ordered by name.

### 5. View: filter bar + summary

In `resources/views/livewire/reports/project-budget.blade.php`, directly above the
existing time-entries table, add a filter bar mirroring the `BulkMove` grid of labelled
`<select wire:model.live="...">` / date inputs:

`Month` · `From` · `To` · `Task` · `User` · a **"Clear filters"** action that resets all
`filter*` props.

Above the table, render the filtered-totals summary line when any filter is active. The
budget stat cards and monthly breakdown markup are left exactly as they are.

## Testing

A Livewire feature test in `tests/Feature` (following existing Livewire test patterns)
that seeds one project with time entries spanning multiple months, users and tasks, then:

- filters by month → asserts the entries list and totals narrow to that month;
- filters by task → asserts narrowing;
- filters by user → asserts narrowing;
- filters by month + task combined → asserts the intersection (the FLTR-2423 use case);
- asserts selecting a month clears a previously-set custom range (and vice versa);
- asserts the budget stat cards / monthly breakdown are unchanged by filters.

## Verification

After automated checks pass, hand off for local testing: start the app (Herd serves
`https://internal-tools.test`), log in via `/demo-login`, open a project budget report
(e.g. `/reports/projects/140/budget`), and drive the filters — pick a month, a task, a
user, and a combination — confirming the list and the totals line update while the budget
cards and monthly breakdown stay put.
