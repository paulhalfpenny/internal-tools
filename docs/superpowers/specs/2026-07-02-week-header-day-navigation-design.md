# Week Header Day Navigation Design

## Goal

Users on the week timesheet should be able to click a weekday/date column header and open the day view for that exact date, matching the familiar Harvest interaction. The navigation must preserve the current viewing context and protect unsaved week edits from being lost accidentally.

## Current Context

The week view is rendered by `App\Livewire\Timesheet\WeekView` at `/timesheet/week`, `/team/{user}/week`, and `/admin/timesheets/{user}/week`. The table header already receives a `$weekDays` collection, and the existing Day/Week toggle builds the correct day-view route for the currently selected date.

Week cells are edited inline with Livewire `wire:model.live.blur` inputs. Leaving the page before saving can discard pending week edits, so header navigation needs a guard.

## Approach

Use normal anchor links for each day/date table header. This keeps the behavior simple, accessible, and browser-friendly while preserving standard open-in-new-tab behavior.

Each header link will point to the day view for that column's date:

- Personal timesheet: `route('timesheet', ['date' => YYYY-MM-DD])`
- Team read-only timesheet: `route('team.timesheet', ['user' => $viewedUser, 'date' => YYYY-MM-DD])`
- Admin impersonation/editing: `route('admin.timesheets.user', ['user' => $viewedUser, 'date' => YYYY-MM-DD])` when the current route is admin scoped

The visible header styling should remain close to the current table header. The only interaction change should be hover/focus affordance indicating the date is clickable.

## Unsaved-Change Protection

The week view table will track a small client-side dirty flag. Editing any week cell marks the page dirty. A successful save clears the dirty flag.

When a dirty user clicks a day/date header, the browser shows a confirmation prompt before navigation. If the user cancels, they remain on the week view with edits intact. If they confirm, the browser follows the day-view link.

The guard should apply only to the new header links. Existing browser refresh/back behavior is out of scope for this change.

## Testing

Add feature-level assertions that week headers render links for the correct day-view routes in personal, team, and admin contexts where coverage already exists or can be added cleanly.

Add template/markup assertions for the dirty-guard hook so the day header links cannot regress back to inert text.

Run the relevant week/day timesheet tests, then the full PHP test suite, Node tests, asset build, and whitespace check.
