# Asana App Components: in-task time logging — Design

**Date:** 2026-07-03
**Ticket:** FLTR-2292 — [Feature Request: Integration with Asana](https://app.asana.com/1/155579732034488/project/1216112855566139/task/1216153275867416) (requested by David Page)
**Status:** Approved (Paul, 2026-07-03) — reuse existing OAuth app; Phases 1, 2 and 3 (timers) all in scope.

## Goal

Replicate the Harvest-in-Asana experience inside our own Asana workspace:

1. A Filter Internal Tools entry appears on every Asana task. Clicking it opens a
   small form with the **internal project pre-selected**, the **Asana task title
   pre-filled into notes** (editable), plus task/hours/date fields.
2. Submitting creates a time entry in Internal Tools, linked via
   `time_entries.asana_task_gid` (so the existing hours-custom-field sync keeps
   working unchanged).
3. A **widget** on the task shows time already logged against it.
4. Users can also **start and stop a timer** from the task instead of entering
   hours after the fact, mirroring the day view's timer behaviour.

## Platform mechanics (verified against Asana docs, 2026-07-03)

- **App Components** on our existing Asana OAuth app (same client id/secret —
  `services.asana.*` in `config/services.php`). Components are configured in the
  Asana developer console alongside the OAuth settings.
- **Distribution:** "Manage distribution" → *Specific workspaces* → Filter only.
  Private apps require no App Directory review and can change settings anytime.
- **Modal form:** Asana `GET`s our form-metadata endpoint when the user opens
  the form, and `POST`s the submission to our `on_submit` callback. Fields can
  be watched (`is_watched`) so changing the Project dropdown re-fetches the form
  with an updated Task dropdown.
- **Widget:** shown when a task has an attached resource URL matching our
  configured pattern. Asana `GET`s our widget endpoint on every task open; we
  return a `summary_with_details_v0` payload (title, up to 5 fields, footer).
  Our `on_submit` response attaches the resource so the widget appears after the
  first logged entry.
- **Security:** every request carries `x-asana-request-signature` — HMAC-SHA256
  of the query string (GET) or the JSON `data` field (POST), keyed with the
  app's **client secret** — plus an `expires_at` parameter. We verify both.
- **Identity:** Asana sends the acting user's gid (`user` param). We map it via
  the existing `users.asana_user_gid` (populated by our per-user Asana OAuth).

## Architecture

### New HTTP surface (`routes/web.php`, outside the `auth` group)

| Route | Purpose |
|---|---|
| `GET /asana-app/form` | Form metadata (project/task/hours/date/notes fields) |
| `POST /asana-app/submit` | Create the time entry |
| `GET /asana-app/widget` | Widget payload for an attached resource URL |
| `GET /asana-app/tasks/{gid}` | Human-facing fallback for the resource URL — redirects to the day view (`/timesheet`) or project budget page |

All except the last sit behind a new `VerifyAsanaAppSignature` middleware. They
use Asana's signature for auth, **not** the web session — an Asana user gid is
resolved to a `User` per request.

### New components

- **`app/Http/Middleware/VerifyAsanaAppSignature.php`** — recompute
  HMAC-SHA256 with `config('services.asana.client_secret')`, compare with
  `hash_equals()`, reject expired `expires_at`. Aborts 401 on failure.
- **`app/Http/Controllers/Integrations/AsanaAppController.php`** — the three
  endpoints. Thin: resolves the user, delegates to the service, shapes JSON.
- **`app/Domain/TimeTracking/AsanaAppFormService.php`** — builds form metadata
  and handles submission. Reuses:
  - `HoursParser` for `0.25` / `0:15` / `90m` input.
  - `TimeEntryService::create()` for billing snapshots.
  - The project/task/Asana validation currently in
    `InternalMcpActions::ensureProjectTaskIsUsable()` — **extract** that private
    method into a shared `app/Domain/TimeTracking/ProjectTaskUsability.php`
    (used by both MCP and this service) rather than duplicating it.
- **`app/Domain/TimeTracking/AsanaProjectAssociationService.php`** + migration
  `asana_project_associations` (`user_id`, `asana_project_gid`, `project_id`,
  `task_id`, `last_used_at`, unique on user+gid) — remembers the user's
  last-used internal project/task per Asana board. Mirrors
  `CalendarEventAssociationService` / `calendar_event_associations`.

### Form definition and prefill

Resolution order for the pre-selected project when the form opens for task gid `T`:

1. `AsanaTask::find(T)` → `asana_project_gid` → internal projects linked via
   `Project::asanaProjects()` (filtered to unarchived projects the user is
   assigned to).
2. Exactly one match → pre-select it. Multiple → pre-select the user's
   last-used association for that board, else the first alphabetically.
   Zero → no preselection; project dropdown lists all the user's projects.
3. Task not in our `asana_tasks` sync yet → same as zero-match, and notes are
   prefilled from the `task` name Asana passes if available (otherwise left
   blank rather than failing).

Fields:

- **Project** — `dropdown`, `is_watched: true` (change re-fetches the form so
  the Task dropdown updates). Options: the user's unarchived projects, labelled
  with `timesheetDisplayName()`.
- **Task** — `dropdown`, options from the selected project's unarchived tasks.
- **Hours** — `single_line_text`, placeholder `0.25 or 0:15`.
- **Date** — `date`, default today.
- **Notes** — `multi_line_text`, prefilled with the Asana task title.
- **Timer** — `checkbox`, "Start a timer instead of logging hours". When
  checked, Hours becomes optional (any value entered seeds the timer, matching
  `DayView::startTimerFromModal()`). Form metadata is built per user per
  request, so when the acting user already has a running timer on *this* task,
  the checkbox is replaced by "Stop the running timer" and the form's
  description shows the elapsed time. `TimeEntryService::startTimer()` already
  auto-stops any other running timer, same as the day view.

The Asana task gid is carried through the form round-trip (Asana echoes the
task context on submit), and the created entry always sets `asana_task_gid` —
we do **not** show an Asana-task picker; the task the user is standing on *is*
the Asana task.

Unlinked user (no `asana_user_gid` match): return a form whose only content is
an instruction + link to `https://internal.filter.agency/profile/asana` to
connect, instead of erroring.

### Submission

1. Verify signature (middleware) → resolve `User` by `user` gid; 401-style form
   error if unknown.
2. Validate hours via `HoursParser` (per-field form errors on failure), project
   /task via the shared usability check. `asana_task_gid` requirement is
   inherently satisfied.
3. `TimeEntryService::create()` with `asana_task_gid` set. Timer checkbox
   checked → hours optional (default 0), then `startTimer()` on the new entry;
   "Stop the running timer" mode → `stopTimer()` instead of creating an entry.
4. `AsanaProjectAssociationService::remember()` for the board → project/task.
5. Bust nothing: pickers are unaffected (no project/task change).
6. Response: success + attach resource
   `https://internal.filter.agency/asana-app/tasks/{gid}` so the widget appears.

### Widget (`GET /asana-app/widget`)

> **Revised after live testing (2026-07-03):** attaching the widget's resource
> card *replaces* the app's "Log time" entry point in the task's Apps row,
> which kills repeat logging — an undocumented platform behaviour. Submits
> therefore never attach a card, and the logged-so-far totals are shown as a
> read-only line inside the form instead. The widget endpoint below remains
> live to serve any historically attached cards, but no new cards are created.
> Final design after live testing: Asana's client renders a generic error
> for ANY non-attachment submit response, and ANY form-created attachment
> (regardless of URL pattern) claims the app's slot on the task, hiding the
> "Log time" button. So the attachment IS the widget, attached on first
> submit, showing live totals permanently — and repeat logging goes through
> the card's link, which deep-links to /timesheet?log_asana={gid} where the
> entry modal opens prefilled (DayView::openModalForAsanaTask). Validation
> errors return 400 + the re-rendered form with an error line. A browser
> extension (separate spec) may later restore fully in-Asana repeat logging.

Input: the attached `resource_url` (contains the task gid) + acting user gid.
Output (`summary_with_details_v0`):

- **Title:** "Time logged" (subtitle: project name if unambiguous).
- **Fields (≤5):** total hours (all users) via
  `TimeEntry::where('asana_task_gid', $gid)->sum('hours')`, the acting user's
  own total, last entry date, entry count. When a timer is running on the task,
  a `pill` field shows "Timer running" with the owner's name.
- **Footer:** `updated` with the latest entry timestamp.
- Cache the payload for 60 seconds per task gid (`Cache::remember`) — the
  endpoint fires on every task open.
- Hours format respects the *viewing* user's `hoursDisplayFormat()` preference.

## Config / console changes

- No new env vars — reuses `ASANA_CLIENT_SECRET`.
- Asana developer console (manual, Paul): enable App Components on the existing
  app; set form URL, on-submit URL, widget URL, and resource attach pattern
  `https://internal.filter.agency/asana-app/*`; distribution → Filter workspace;
  install the app to the workspace.

## Testing

- **Unit:** signature middleware (valid/invalid/expired vectors — deterministic
  HMAC fixtures); association service lookup/remember.
- **Feature (signed requests):** form metadata for linked user with mapped
  board (asserts preselection + notes prefill); unlinked-user form; submit
  happy path (entry created with `asana_task_gid`, association remembered);
  submit validation errors (bad hours, task not on project); widget payload
  totals and caching.
- **Manual/E2E:** Asana must reach the server, so the in-Asana experience is
  verified against production after deploy (or an `expose`/ngrok tunnel to
  local if pre-deploy verification is wanted). Endpoint behaviour itself is
  fully covered locally by the signed-request tests.

## Out of scope (follow-ups)

- Rule actions / automation components.
- Editing or deleting existing entries from the widget.
- Timer stop from the *widget* — widgets are display-only; stopping happens via
  the form (see Timer field) or in Internal Tools.

## Risks

- Form UI is limited to Asana's native field set — functional, not our in-app
  picker. Accepted.
- Only Asana-connected users can log time this way; the unlinked-user form
  handles onboarding.
- The signed endpoints are unauthenticated in the session sense — the HMAC
  check is the security boundary, so the middleware must reject *before* any
  DB access, and secrets comparison must be constant-time.

## Estimate

Phase 1 (form + submit + shared validation extraction + tests): ~1 day.
Phase 2 (widget + caching + tests): ~0.5 day.
Phase 3 (timer start/stop on the form + widget pill + tests): ~0.5 day —
small because `TimeEntryService::startTimer()/stopTimer()` already exist with
auto-stop semantics and test coverage.
Console setup + prod verification: ~0.5 day.
