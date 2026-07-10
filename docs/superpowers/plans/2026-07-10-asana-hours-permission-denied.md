# Asana Hours Permission-Denied Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop retrying Asana hours-sync 403 responses and expose enough context to repair the affected board permissions.

**Architecture:** The queue job owns status-specific policy and records a terminal permission error. The shared Asana HTTP client retries only connection failures and 5xx responses, allowing jobs to handle 4xx responses immediately.

**Tech Stack:** Laravel 11, Laravel HTTP client, queued jobs, Eloquent, Pest 3.

## Global Constraints

- Never log OAuth access or refresh tokens.
- Preserve existing 404 and 429 behavior.
- Do not fall back to a human actor after a designated actor receives 403.
- Keep the change scoped to Asana hours-sync permission handling.

---

### Task 1: Permission-Denied Regression Tests

**Files:**
- Modify: `tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`

**Interfaces:**
- Consumes: `SyncAsanaTaskHoursJob::handle(AsanaService, AsanaTaskHoursAggregator): void`
- Produces: Regression coverage for `asana.sync_hours.permission_denied`.

- [x] **Step 1: Write the set-hours 403 test**

Create a linked board, designated actor, cached task, and time entry. Fake the
task PUT as 403, call the job, and assert one request, no thrown exception, an
actionable entry error, and log context containing stage, board, field, task,
project, and actor identifiers.

- [x] **Step 2: Run the test to verify RED**

Run: `php artisan test tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php --filter='permission denied while setting hours'`

Expected: FAIL because the current job throws the 403 and does not write the
permission-denied log.

- [x] **Step 3: Write the ensure-field 403 test**

Create a linked board with no stored field GID. Return 403 from the project
custom-field-settings request and assert the same terminal behavior with stage
`ensure_field` and a null field GID.

- [x] **Step 4: Run the test to verify RED**

Run: `php artisan test tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php --filter='permission denied while ensuring field'`

Expected: FAIL because the ensure-field catch logs a generic failure and throws.

### Task 2: Terminal 403 Handling

**Files:**
- Modify: `app/Jobs/Asana/SyncAsanaTaskHoursJob.php`
- Test: `tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`

**Interfaces:**
- Consumes: `RequestException`, resolved project, board, actor, task, and field.
- Produces: `handlePermissionDenied(...)` that marks entries and logs context.

- [x] **Step 1: Handle 403 at both failure stages**

Catch `RequestException` separately around `ensureHoursCustomField`. For status
403, call a private permission handler and return. Keep non-403 exceptions on
the generic failure path. Add the equivalent 403 branch before 404/429 handling
for `setTaskHours`.

- [x] **Step 2: Add the permission handler**

The handler writes `Asana sync account cannot update hours on Asana board
"{name}". Grant it project-admin and custom-field edit access, then retry.` to
matching entries and logs `asana.sync_hours.permission_denied` with:

```php
[
    'stage' => $stage,
    'asana_task_gid' => $this->asanaTaskGid,
    'asana_task_name' => $asanaTask->name,
    'board_gid' => $linkedBoard->gid,
    'board_name' => $linkedBoard->name,
    'custom_field_gid' => $fieldGid,
    'project_id' => $this->projectId,
    'actor_user_id' => $actor->id,
    'actor_asana_user_gid' => $actor->asana_user_gid,
    'error' => $exception->getMessage(),
]
```

- [x] **Step 3: Run targeted tests to verify GREEN**

Run: `php artisan test tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`

Expected: all job tests pass with no warnings.

### Task 3: Transient-Only HTTP Retries

**Files:**
- Modify: `app/Services/Asana/AsanaService.php`
- Modify: `tests/Feature/Asana/AsanaServiceTest.php`

**Interfaces:**
- Consumes: Laravel HTTP retry callback receiving `Throwable`.
- Produces: two attempts only for connection failures or responses with status at least 500.

- [x] **Step 1: Write retry-policy tests**

Add one test returning 403 on `setTaskHours` and assert one request plus a
`RequestException`. Add another returning 500 then 200 and assert two requests
and successful completion.

- [x] **Step 2: Run retry tests to verify RED**

Run: `php artisan test tests/Feature/Asana/AsanaServiceTest.php --filter='retry'`

Expected: the 403 count assertion fails because the current client retries all
unsuccessful responses.

- [x] **Step 3: Filter retries**

Pass a callback to `PendingRequest::retry` that returns true for
`ConnectionException` and for `RequestException` responses whose status is at
least 500, and false for other exceptions.

- [x] **Step 4: Run service tests to verify GREEN**

Run: `php artisan test tests/Feature/Asana/AsanaServiceTest.php`

Expected: all service tests pass with no warnings.

### Task 4: Runbook and QA

**Files:**
- Modify: `docs/asana-deployment.md`

**Interfaces:**
- Consumes: New permission-denied event and operational recovery flow.
- Produces: Explicit project-admin, field-access, and retry instructions.

- [x] **Step 1: Update the runbook**

Require the sync account to be a project admin with edit access to the hours
field. Document the new event and targeted failed-job retry after access is fixed.

- [x] **Step 2: Run formatting and static analysis**

Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G`

Expected: both commands exit 0.

- [x] **Step 3: Run full automated QA**

Run: `php artisan test && npm run build`

Expected: all tests pass and Vite exits 0.

- [x] **Step 4: Review the final diff**

Run: `git diff --check && git diff --stat origin/main...HEAD`

Expected: no whitespace errors and only the scoped job, service, tests, and docs changed.
