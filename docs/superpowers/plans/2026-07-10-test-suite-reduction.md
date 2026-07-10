# Test Suite Reduction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce the default repository test surface from 515 executed cases to no more than 128 while retaining representative coverage of security, permissions, billing, data integrity, destructive actions, and external integrations.

**Architecture:** Keep a risk-weighted regression suite rather than an even percentage from every file. Delete duplicate framework-wiring, rendered-markup, mirrored day/week, micro-formatting, and implementation-detail tests; do not skip or merely exclude them. Production code, CI commands, and test infrastructure remain unchanged.

**Tech Stack:** Laravel 11, Pest 3, PHPUnit 11, Livewire 4, Node.js built-in test runner.

## Global Constraints

- Baseline is 503 PHP cases plus 12 Node cases: 515 total.
- Final surface must be at most 128 executed cases (25% of baseline, rounded down).
- Target is exactly 125 PHP cases plus 3 Node cases: 128 total (24.85%).
- Change test files only; do not alter production behavior or CI/deploy configuration.
- Delete unselected tests rather than marking them skipped, excluded, or quarantined.
- Preserve test helpers/imports needed by retained cases and remove only imports/helpers proven unused.
- Do not commit, push, deploy, or publish.

---

### Task 1: Core, admin, API, MCP, notification, profile, and unit tests

**Files:**
- Modify/Delete: `tests/Feature/Admin/*.php`
- Modify/Delete: `tests/Feature/Api/*.php`
- Delete: `tests/Feature/Auth/GoogleSsoTest.php`
- Modify: `tests/Feature/Domain/Billing/RateResolverTest.php`
- Modify/Delete: `tests/Feature/Mcp/*.php`
- Delete: `tests/Feature/Models/UserTest.php`
- Modify/Delete: `tests/Feature/Notifications/*.php`
- Modify/Delete: `tests/Feature/Profile/*.php`
- Modify/Delete: `tests/Unit/**/*.php`

**Interfaces:**
- Consumes: Existing Pest cases and shared `tests/TestCase.php` database setup.
- Produces: Exactly 52 retained cases from the 208-case scope.

- [ ] **Step 1: Retain the exact risk-weighted cases**

Retain these per-file counts and delete every unlisted case: `AdminTimesheetEditingTest` 3, `AdminUsersNotificationEditorTest` 1, `Phase3AdminTest` 1, `Phase4RatesTest` 1, `Phase5BulkMoveAndAlertsTest` 3, `ProjectTeamManagementTest` 1, `TaskBillabilityDefaultsTest` 3, `UsersTest` 3, `PersonalAccessTokenTest` 4, `RateResolverTest` 5, `InternalToolsMcpTest` 14, `NotificationKillSwitchTest` 2, `QueuedMailNotificationsTest` 1, `SendTimesheetRemindersCommandTest` 3, `SlackChannelTest` 1, `ApiTokensTest` 1, `PreferencesTest` 1, `HoursFormatterTest` 1, and `HoursParserTest` 3.

- [ ] **Step 2: Delete wholly redundant files**

Delete `TeamsTest.php`, `GoogleSsoTest.php`, `McpProjectPickerCacheTest.php`, `UserTest.php`, `TimesheetCompletionServiceTest.php`, `McpDocumentationTest.php`, and `ExampleTest.php`.

- [ ] **Step 3: Verify the scoped count and behavior**

Run:

```bash
./vendor/bin/pest tests/Feature/Admin tests/Feature/Api tests/Feature/Domain tests/Feature/Mcp tests/Feature/Notifications tests/Feature/Profile tests/Unit --compact
```

Expected: 52 passing tests.

### Task 2: Reports, reporting, services, and timesheet tests

**Files:**
- Modify/Delete: `tests/Feature/Reports/*.php`
- Modify: `tests/Feature/Reporting/*.php`
- Modify: `tests/Feature/Services/CalendarServiceTest.php`
- Modify/Delete: `tests/Feature/Timesheet/*.php`

**Interfaces:**
- Consumes: Existing reporting, billing, timer, and Livewire fixtures.
- Produces: Exactly 31 retained cases from the 124-case scope.

- [ ] **Step 1: Retain financial and data-integrity coverage**

Retain these per-file counts and delete every unlisted case: `ProjectBudgetExportTest` 1, `ProjectBudgetReportTest` 2, `ScopedCsvExportTest` 1, `DetailedTimeCsvExportTest` 4, `TimeReportQueryTest` 5, `TimeEntryServiceTest` 4, `TimerTest` 4, and `WeekViewTest` 4.

- [ ] **Step 2: Retain privacy and essential integration coverage**

Retain `TeamTimesheetViewTest` 2, `CalendarServiceTest` 2, `DayViewAsanaDeepLinkTest` 1, and `TaskPickerDropdownTest` 1. Delete `CalendarEventAssociationTest.php`, `DayViewEditTest.php`, `DayViewHoursDisplayFormatTest.php`, `DayViewModalPollTest.php`, and `ProjectPickerDisplayNameTest.php`.

- [ ] **Step 3: Verify the scoped count and behavior**

Run:

```bash
./vendor/bin/pest tests/Feature/Reports tests/Feature/Reporting tests/Feature/Services tests/Feature/Timesheet --compact
```

Expected: 31 passing tests.

### Task 3: Asana, schedule, console, root feature, budgeting, integration, and Node tests

**Files:**
- Modify/Delete: `tests/Feature/Asana/*.php`
- Modify/Delete: `tests/Feature/Schedule/*.php`
- Modify: `tests/Feature/Console/*.php`
- Modify/Delete: root-level `tests/Feature/*.php`
- Modify: `tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php`
- Modify/Delete: `tests/Feature/Integrations/*.php`
- Modify/Delete: `tests/Node/*.test.mjs`

**Interfaces:**
- Consumes: Existing external-service fakes, scheduling fixtures, import fixtures, and Node source-module imports.
- Produces: Exactly 42 retained PHP cases from the 171-case scope and 3 retained Node cases from 12.

- [ ] **Step 1: Retain the selected Asana and scheduling cases**

Retain 20 Asana cases: settings authorization 1, project-board linking 2, scheduled refresh 1, OAuth 2, token management 2, task-required validation 3, pull jobs 3, hours sync 5, and observer dispatch 1. Retain 8 schedule cases: schedule board 5, availability 2, and actuals 1. Delete wholly redundant Asana service, sync actor, task-hours aggregator, and task-picker filter files.

- [ ] **Step 2: Retain selected command, import, budget, and embedded integration cases**

Retain console cases 4, root feature cases 5, budget calculator cases 3 (`fixed-fee budget — actuals are sum of billable time`, `monthly CI — cumulative budget rolls over (under then over)`, `monthly CI — entries before budget start date are excluded`), and embedded Asana integration cases 2 (`saving logs the entry against the Asana task and remembers the choice`, `stopping the timer banks the elapsed time and notifies the extension`). Delete every other case in this scope, including `AsanaTimerStatusTest.php`.

- [ ] **Step 3: Retain three Node behavior cases**

Retain `typing searches all linked board tasks instead of the project prefilter`, `typing an Asana task URL matches the cached task gid`, and `enhances single-select dropdowns with at least two enabled choices`; delete all other Node cases and any now-empty Node test files.

- [ ] **Step 4: Verify the scoped count and behavior**

Run:

```bash
./vendor/bin/pest tests/Feature/Asana tests/Feature/Schedule tests/Feature/Console tests/Feature/Budgeting tests/Feature/Integrations tests/Feature/AppVersionGuardTest.php tests/Feature/HarvestImportTest.php tests/Feature/SecurityHeadersTest.php --compact
node --test tests/Node/*.test.mjs
```

Expected: 42 passing PHP tests and 3 passing Node tests.

### Task 4: Whole-suite verification

**Files:**
- Verify: all retained test files
- Verify: production code remains unchanged

**Interfaces:**
- Consumes: Tasks 1-3 retained suites.
- Produces: Evidence that the suite is cap-compliant and green.

- [ ] **Step 1: Count listed PHP tests**

Run:

```bash
php artisan test --list-tests | awk '/^ - / { count++ } END { print count }'
```

Expected: `125`.

- [ ] **Step 2: Run the complete PHP suite**

Run:

```bash
php artisan test --compact
```

Expected: 125 passing tests.

- [ ] **Step 3: Run the complete Node suite**

Run:

```bash
node --test tests/Node/*.test.mjs
```

Expected: 3 passing tests.

- [ ] **Step 4: Verify the build and test-only diff**

Run:

```bash
npm run build
git diff --stat
git status --short
```

Expected: build exits 0; only test files and this plan are changed; final executed surface is 128/515 (24.85%).
