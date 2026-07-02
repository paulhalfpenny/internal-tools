# Week Header Day Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Week-view weekday/date headers navigate to the matching day view while protecting unsaved week edits.

**Architecture:** Keep navigation as normal anchor links generated server-side from the same route context as the existing Day/Week toggle. Add a tiny Alpine dirty-state guard around the week table so edited cells prompt before header navigation and successful saves clear the dirty state.

**Tech Stack:** Laravel 11, Livewire, Blade, Alpine.js, Pest.

## Global Constraints

- Preserve personal, team, and admin timesheet route context.
- Use normal anchor links for weekday/date headers.
- Protect unsaved week edits only for the new day/date header links.
- Do not commit changes unless explicitly requested.

---

### Task 1: Add Failing Coverage For Day Header Links

**Files:**
- Modify: `tests/Feature/Timesheet/WeekViewTest.php`
- Modify: `resources/views/livewire/timesheet/week-view.blade.php`

**Interfaces:**
- Consumes: `WeekView` rendered HTML.
- Produces: Header links with `data-week-day-link`, `href`, and `data-unsaved-week-guard`.

- [ ] **Step 1: Write failing tests**

Add tests that render personal, team, and admin week views and assert the Monday header links to the matching day route with the current date query.

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Feature/Timesheet/WeekViewTest.php --filter='week day headers'`
Expected: FAIL because the week header currently renders plain text, not links.

- [ ] **Step 3: Implement header links**

In `resources/views/livewire/timesheet/week-view.blade.php`, compute the day URL for each `$day` using the current route context, then wrap the weekday/date text in an `<a>` with `data-week-day-link` and `data-unsaved-week-guard`.

- [ ] **Step 4: Verify tests pass**

Run: `php artisan test tests/Feature/Timesheet/WeekViewTest.php --filter='week day headers'`
Expected: PASS.

### Task 2: Add Dirty-State Guard

**Files:**
- Modify: `tests/Feature/Timesheet/WeekViewTest.php`
- Modify: `resources/views/livewire/timesheet/week-view.blade.php`

**Interfaces:**
- Consumes: Week cell inputs and save button.
- Produces: Alpine state `{ hasUnsavedWeekChanges, markWeekDirty, confirmWeekDayNavigation, clearWeekDirty }`.

- [ ] **Step 1: Write failing markup test**

Assert week cell inputs call `markWeekDirty()` on input/change, header links call `confirmWeekDayNavigation($event)`, and the save button clears dirty state after a successful Livewire save.

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/Timesheet/WeekViewTest.php --filter='protects unsaved week edits'`
Expected: FAIL because no dirty-state hooks exist yet.

- [ ] **Step 3: Implement dirty guard**

Add `x-data` to the week table wrapper. Mark dirty from editable week cells. Use `window.confirm('You have unsaved changes on this week. Leave without saving?')` from header-link clicks when dirty. Clear dirty state from the save button after `$wire.save()`.

- [ ] **Step 4: Verify tests pass**

Run: `php artisan test tests/Feature/Timesheet/WeekViewTest.php --filter='week day headers|protects unsaved week edits'`
Expected: PASS.

### Task 3: Final Verification

**Files:**
- Verify all modified files.

**Interfaces:**
- Consumes: All changes from Tasks 1-2.
- Produces: Verified implementation ready for user testing.

- [ ] **Step 1: Run focused tests**

Run: `php artisan test tests/Feature/Timesheet/WeekViewTest.php tests/Feature/Timesheet/DayViewEditTest.php`
Expected: PASS.

- [ ] **Step 2: Run full verification**

Run: `php artisan test`
Expected: PASS.

Run: `node --test tests/Node/*.test.mjs`
Expected: PASS.

Run: `npm run build`
Expected: PASS.

Run: `git diff --check`
Expected: no output and exit 0.
