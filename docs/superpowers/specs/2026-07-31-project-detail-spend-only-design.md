# Neutral Project Detail with Spend-Only Reporting — Design

**Date:** 2026-07-31
**Pages affected:** `/reports/projects` and the new `/reports/projects/{project}`

## Problem

The Projects report currently links only projects with a configured budget to
`/reports/projects/{project}/budget`. Projects without a budget are not
navigable from the report even though they have useful recorded spend and time
data. The existing destination is also named and structured as a budget page,
which does not accurately describe a no-budget project.

## Goal

Give every project in the Projects report a neutral detail page. Budgeted
projects retain the existing budget analysis. Projects without a budget receive
a spend-only view showing per-month spend, cumulative spend, hours, and the
same filterable time-entry list and CSV export.

## Scope and constraints

- The canonical route is `/reports/projects/{project}`, named
  `reports.projects.detail`.
- The old `reports.projects.budget` route remains temporarily as a redirect to
  the canonical detail route, preserving existing bookmarks and budget-alert
  notification links.
- Every project name in the Projects report links to the canonical detail
  route, regardless of budget configuration.
- Budgeted projects must preserve their current calculations and presentation.
- Spend-only projects do not show budget, variance, or percentage-used fields.
- Existing report access control (`can:access-reports`) remains unchanged.
- The existing time-entry filters and export remain available to both detail
  states. Filters continue to affect only the entries table and its filtered
  totals, not the monthly project analysis.

Out of scope: changing budget configuration, changing the calculations for
budgeted projects, shareable filter state, or adding a general project-edit
link for report users.

## Recommended approach

Use one renamed Livewire component, `ProjectDetail`, and one Blade view. It
loads the project, shared time-entry data and a monthly spend series for every
project. It calculates and passes `BudgetStatus` only for projects with a
budget. The view switches only the summary and monthly-analysis sections based
on whether that status exists; the page header, export action, filters, and
time-entry table are shared.

This avoids two almost-identical detail components and gives users one stable
URL for every project. It is preferable to keeping the current budget-named
route because the new URL accurately represents both supported detail states.

## Route and compatibility design

1. Add `GET /reports/projects/{project}` using `ProjectDetail`, named
   `reports.projects.detail`.
2. Change `GET /reports/projects/{project}/budget` to a named redirect route
   (`reports.projects.budget`) which redirects to `reports.projects.detail`.
3. Update all internal links that open a project detail, including the Projects
   report and the client-detail report, to use `reports.projects.detail`.
4. Keep `BudgetThresholdReached` using `reports.projects.budget` until the
   redirect route is in place, then update it to the canonical route. The
   retained redirect protects already-sent notifications.

The neutral detail route must be declared before the legacy `/budget` route so
the static suffix remains unambiguous in Laravel's route matching.

## Monthly spend data

Extract the time-entry grouping portion of
`ProjectBudgetCalculator::monthlyBreakdown()` into a neutral monthly-spend
method. It accepts a project and an effective reporting start date, returns one
row per calendar month through the current month, and includes:

- `month` and `month_label`;
- `month_amount` and `month_hours` from billable entries only;
- `running_amount` and `running_hours` as cumulative totals.

The effective start date is the project's `budget_starts_on` or `starts_on`,
falling back to its earliest time entry; a project with no start date and no
entries produces no monthly rows. This prevents an artificial trailing year of
empty months for no-budget projects.

The budget calculator then enriches that common spend series with monthly and
running budget/variance fields only when rendering a budgeted project. Its
fixed-fee and monthly-CI calculations remain unchanged.

## Detail page states

### Shared content

Both states render the project/client heading, Projects report back-link, CSV
export button, time-entry filters, filtered totals, paginated time-entry table,
and the existing empty states.

### Budgeted project

Retain the current cards for this month and lifetime/cumulative metrics, plus
the monthly table with budget, spent, running budget, running spent, and
running variance. Copy and number formatting stay unchanged.

### No-budget project

Render only spend information:

- a **This month** card for spent amount and hours;
- a **Cumulative** card for total spend and hours across the effective project
  lifetime;
- a monthly table with **Month**, **Spent (£)**, **Spent (hrs)**,
  **Cumulative spent (£)**, and **Cumulative hrs**.

Do not show the current "This project has no budget configured" notice, a link
to configure a budget, budget figures, variance, or percentage-used values.
The current calendar month remains highlighted in both monthly tables.

## Tests

Extend the existing project-budget report feature coverage (renamed to project
detail coverage) with these cases:

1. Every Projects report row renders a link to `reports.projects.detail`, for
   both a budgeted and an unbudgeted project.
2. The canonical detail route is accessible to report-authorised users for an
   unbudgeted project and renders spend-only cards, monthly rows, and its own
   time entries.
3. The no-budget page does not render budget/variance/%-used labels or the
   budget-configuration prompt.
4. Monthly spend includes billable entries, produces correct per-month and
   cumulative amount/hour totals, and excludes non-billable entries from spend
   totals consistently with the existing budget report.
5. The existing budgeted-page assertions continue to pass unchanged in
   substance.
6. The legacy `/budget` URL redirects to the canonical neutral URL.
7. Existing filter and export tests still work when mounted against the renamed
   component for both project types.

## Verification

Run the focused project-detail, budget-calculator, filtering, and export test
files, then the full relevant report test suite. Manually verify one budgeted
and one unbudgeted project in the local app: links from Projects and Clients
reports, monthly cumulative values, filters, pagination, CSV export, and the
legacy bookmarked budget URL.
