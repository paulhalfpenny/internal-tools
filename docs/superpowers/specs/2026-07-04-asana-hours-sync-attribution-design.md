---
title: Asana Hours-Sync Attribution — dedicated bot account
date: 2026-07-04
status: approved
---

# Asana Hours-Sync Attribution Design

## Problem

The "Hours tracked (Internal Tools)" custom field on an Asana task shows the
**cumulative sum of every user's** Internal Tools time against that task, pushed
by [`SyncAsanaTaskHoursJob`](../../../app/Jobs/Asana/SyncAsanaTaskHoursJob.php).

The API write is made with an *admin's* OAuth token, chosen by `pickActor()`,
which orders candidates by role only (`admin` → `manager` → `member`) with **no
tiebreaker among admins**. So Asana attributes the change to whichever admin the
query happens to return first — a real person who did not make the change and may
never have referenced the task.

This surfaced in FLTR-2383: Asana recorded *"Chris Murfin changed Hours tracked
(Internal Tools) from 1.00 to 1.75"* on task `1215963310734388`. The production
audit trail showed the underlying hours were actually logged by **Lech Boron
(1.00h)** and then **Phil Dempsey (0.75h)**; Chris only appeared because the sync
picked his admin token as the actor. Two defects:

1. **Misleading attribution** — changes are credited to a real admin, never the
   person who logged the time.
2. **Non-deterministic actor** — with no tiebreaker among admins, the credited
   "author" can drift between syncs.

## Goal

Attribute these writes to a dedicated bot identity so the Asana activity feed
reads honestly (e.g. *"Internal Tools changed Hours tracked…"*) instead of
blaming a random admin.

## Approach

Use a **dedicated bot Asana account** (`internaltools@filteragency.com`),
connected through the existing OAuth flow like any other user, and designate it
— from the admin Asana Settings page — as the account whose token the hours-sync
job uses. If the bot account is unavailable at sync time, the job **falls back to
the existing admin-priority actor but logs a warning**, so hours never stop
flowing while the misconfiguration is surfaced for repair.

Rejected alternatives:

- **Config/`.env` pointer** (`ASANA_SYNC_ACTOR_USER_ID`) — changing it needs a
  deploy, it's invisible on the admin page, and the bot must be a connected user
  regardless. No upside over a DB-backed, self-service pointer.
- **Asana Service Account token** — cleaner (no member seat) but gated to
  Enterprise/Advanced tiers; not assumed available.
- **Generic settings table** — no key-value settings store exists today; building
  one for a single pointer is overkill.

## One-time operational setup (not code)

1. Create the Asana account `internaltools@filteragency.com`.
2. Add it to the Filter workspace (`155579732034488`) and the boards whose
   projects sync hours; authorize the Internal Tools Asana app for it.
3. Sign into Internal Tools as the bot user and connect Asana via the normal
   profile OAuth flow, so it becomes a connected user (has
   `asana_access_token` + `asana_user_gid` + `asana_workspace_gid`).
4. An admin opens **Admin → Asana Settings** and selects the bot account as the
   designated sync account.

This setup is documented in `docs/asana-deployment.md` as part of the change.

## Components

### 1. Designation store

- Migration: add nullable boolean `is_asana_sync_actor` to `users` (default
  `false`).
- `User` model:
  - Add `is_asana_sync_actor` to `$fillable`/casts (`boolean`).
  - `scopeAsanaSyncActor()` → the single flagged user (or `null`).
  - A guarded setter (e.g. `designateAsanaSyncActor(User $user)`), run in a
    transaction, that clears the flag on any other user before setting it on the
    target. This enforces the **at-most-one** invariant regardless of UI.

### 2. Actor selection — core change

`SyncAsanaTaskHoursJob::pickActor(string $workspaceGid): ?User` becomes:

1. **Designated bot first.** Load `User::asanaSyncActor()`. Use it if it has a
   non-null `asana_access_token` **and** its `asana_workspace_gid === $workspaceGid`.
   (Role and `is_active` are intentionally ignored — it's an explicit choice.)
2. **Fallback.** Otherwise, the existing admin → manager → member query.
   - If a bot **was** designated but unusable (no token / workspace mismatch),
     log `AsanaSyncLog::warn('asana.sync_hours.actor_fallback', {...reason})`
     before returning the fallback actor.
   - If **no** bot is designated at all, fall back silently (current behaviour —
     no new noise for installs that haven't set this up).

The reason codes distinguish `no_actor_designated` (silent), `actor_no_token`,
and `actor_workspace_mismatch` (both warn).

### 3. Admin UI

[`AsanaSettings`](../../../app/Livewire/Admin/Integrations/AsanaSettings.php) and
its Blade view already list connected accounts. Add:

- A selector (connected accounts + a "None" option) bound to a Livewire action
  that calls the guarded setter. Gated by `access-admin`, like the rest of the
  component.
- The currently-designated account shown as selected.
- A warning banner when a recent (`>= now()->subDay()`) `actor_fallback` log
  exists, telling the admin the bot is disconnected and hours are being
  attributed to an admin until it's reconnected.

### 4. Fallback visibility

Covered by the `actor_fallback` warn log surfaced in the settings banner and the
existing recent-logs list. Entries still sync; attribution reverts to an admin
only while the bot is unusable — matching the "keep sync flowing, but warn"
decision.

## Data flow

```
TimeEntry saved  →  TimeEntryAsanaObserver  →  SyncAsanaTaskHoursJob
                                                    │
                                        pickActor(workspaceGid)
                                          ├─ designated bot (token + workspace OK) ──► use bot token
                                          └─ else admin-priority actor  ──► use admin token
                                                    (+ warn if bot designated but unusable)
                                                    │
                                        AsanaService->forUser(actor)->setTaskHours(...)
```

## Error handling

- No behavioural change to the existing 404 / 429 / generic-failure handling in
  the job.
- New warn path is log-only; it never throws and never blocks the push.
- The one-at-a-time designation invariant is enforced in the setter
  (transactional), not only in the UI.

## Testing

Feature tests around `SyncAsanaTaskHoursJob` / `pickActor`:

- Prefers the designated bot when it has a token and matching workspace.
- Falls back to admin-priority **and logs `actor_fallback`** when the designated
  bot has no token.
- Falls back **and logs** when the designated bot's workspace mismatches.
- Falls back **silently** (no `actor_fallback` log) when no bot is designated.
- Designating a new sync account clears the flag from the previous one
  (at-most-one invariant).

Admin UI: a Livewire test that selecting an account designates it and "None"
clears it, both gated by `access-admin`.

Local handoff (final gate, for the user): start the app, log in via
`/demo-login`, open **Admin → Asana Settings**, and confirm the sync-account
selector works and the warning banner behaves.

## Out of scope

- Asana Service Accounts (tier-gated).
- Backfilling or correcting the historical misattributed Asana comments.
- Excluding the bot user from timesheet / user-picker lists (follow-up if noisy).
