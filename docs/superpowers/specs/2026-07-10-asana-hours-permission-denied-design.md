---
title: Asana hours-sync permission-denied handling
date: 2026-07-10
status: approved
---

# Asana Hours Permission-Denied Design

## Problem

The cumulative-hours job writes `Hours tracked (Internal Tools)` through the
designated Asana sync account. If that account cannot edit the task or custom
field, Asana returns HTTP 403. The job currently treats 403 as a generic
failure, so Laravel retries the HTTP request and the queue retries the job.
After the final attempt, time entries contain the raw API error and the sync log
does not identify the board, field, or actor that an administrator must fix.

## Goal

Make permission failures immediately actionable without retrying a permanent
authorization error, while keeping transient failures and existing 404/429
behavior unchanged.

## Design

`SyncAsanaTaskHoursJob` handles a `RequestException` with status 403 at both
permission-sensitive stages: creating/attaching the hours field and writing its
value. It marks matching time entries with a concise instruction, writes an
`asana.sync_hours.permission_denied` log, and returns successfully so the queue
does not retry the same permanent failure.

The log context contains the failure stage, task GID and name, board GID and
name, custom-field GID when known, Internal Tools project ID, actor user ID and
Asana user GID, and Asana's original error. This is operational metadata only;
OAuth tokens are never logged.

`AsanaService` retains two HTTP attempts for connection failures and server
errors. Client errors, including 403, 404, and 429, return immediately to the
job so its status-specific policy runs without an extra request.

The admin runbook explicitly requires the designated account to be a project
admin on every linked board and able to edit the hours field. After permissions
are corrected, an administrator retries only the affected failed job or causes
a new sync by saving a matching time entry.

## Alternatives Rejected

- Falling back to a human administrator on 403 would restore misleading Asana
  attribution and hide a broken bot configuration.
- Retrying 403 cannot succeed until external permissions change and creates
  avoidable API traffic and failed-job noise.
- Adding a separate permissions preflight API call would introduce another
  race and request without eliminating the need to handle the write response.

## Testing

- A 403 while setting hours marks entries, writes the actionable log, makes one
  HTTP request, and does not throw.
- A 403 while ensuring the field has the same terminal behavior and records the
  `ensure_field` stage.
- A 500 remains retryable at the HTTP layer and still throws to the queue.
- Existing success, 404, 429, actor-selection, and pull-job tests remain green.
