# Asana browser extension: in-task time logging — Design

**Date:** 2026-07-03
**Follow-up to:** [2026-07-03-asana-app-components-time-logging-design.md](2026-07-03-asana-app-components-time-logging-design.md) (FLTR-2292)
**Status:** Approved (Paul, 2026-07-03). Built in `asana-extension/`; the app-components form/widget endpoints were removed as part of the cutover — only the `/asana-app/tasks/{gid}` deep link remains server-side.

## Why

The app-components integration (shipped) hits a hard platform ceiling: Asana's
form contract requires every successful submit to attach a resource, and any
form-created attachment claims the app's slot on the task — so the in-Asana
"Log time" modal is only reachable before the first entry. Repeat logging goes
through the widget card's deep link into Internal Tools.

Harvest's famous stopwatch icon in the task toolbar is **not** an Asana
integration at all — it's their **browser extension** injecting a button into
Asana's DOM. That mechanism has none of the platform constraints: no
attachments, no widget slot, repeat logging forever. This spec replicates it.

## What it is

A Manifest V3 browser extension (Chrome/Edge; Firefox if anyone needs it)
that:

1. Runs a content script on `https://app.asana.com/*`.
2. Watches for the task-details pane (MutationObserver — Asana is a SPA) and
   injects a Filter "Log time" button into the task toolbar, next to the
   native icons.
3. On click, reads the current task gid and opens a small popup window to
   `https://internal.filter.agency/timesheet?log_asana={gid}` — the deep link
   shipped with the gateway design, which opens the day-view entry modal
   prefilled (mapped project, remembered task, Asana task attached).
4. The user logs time (or starts/stops a timer) in the popup using the full
   Internal Tools modal, then closes it.

No new server-side surface is required — the deep link already exists,
session auth already works, and the hours custom-field sync is unchanged.

### Task gid extraction

Asana task URLs carry the gid as the trailing numeric segment
(`.../task/{gid}` on new-style URLs, `/0/{project}/{gid}` on legacy ones).
Extract from `location.href` at click time; fall back to the task pane's
`data-task-id` attribute if present. Both must be verified against the
current Asana DOM during implementation.

### Popup vs new tab

Popup window (`window.open` with width/height ~480×640) keeps the Asana
context visible behind it — closest to the Harvest experience. The day view
renders fine at that width. Optional polish: a `?popup=1` param that hides
the app chrome (nav bar) on the timesheet for a tighter fit — server-side,
trivial, optional.

## Relationship to the shipped app-components integration

They coexist. The extension becomes the primary path for users who install
it; the Apps-row button (first log) and widget card (totals + deep link)
remain for everyone else and for surfaces where extensions don't run (mobile
apps, browsers without the extension). If the extension proves universal we
can later retire the modal form and keep only the widget.

## Distribution

- **Chrome/Edge:** publish to the Chrome Web Store as **unlisted** — install
  via a shared link, no public listing, no review beyond the standard
  automated pass. (Edge installs Chrome-store extensions directly.)
- Alternatively, for zero store involvement: load-unpacked from a shared
  folder — workable for ~16 people but manual updates make the store route
  better.
- **Firefox:** only if someone uses it (separate AMO unlisted submission).

## Maintenance cost (the honest part)

The button is injected into DOM that Asana can restructure without notice.
Expect it to silently vanish a few times a year until a selector is updated.
Mitigations: anchor on stable attributes (`data-*` hooks, aria labels) rather
than class names; keep the injection logic in one small file; the failure
mode is benign (button missing → users fall back to the widget deep link).

## Build shape

```
asana-extension/
  manifest.json        // MV3, content_scripts on app.asana.com, no host perms beyond that
  content.js           // observer + button injection + popup open
  icon-*.png           // Filter "F" mark
```

No background worker, no storage, no external requests from the extension
itself — everything happens in the opened Internal Tools window. This keeps
the store review trivial and the security surface near zero.

## Estimate

- Extension build + local testing against real Asana: ~half a day.
- Store packaging/unlisted publish + install doc for the team: ~half a day.
- Optional `?popup=1` chrome-less mode: ~1 hour.

## Open questions for Paul

1. Chrome-only to start? (Check what the team actually uses.)
2. Should the button also show logged totals on hover/badge (extra API
   surface: one signed endpoint or reuse of the widget endpoint), or keep
   v1 to "open the modal" only? Recommend: v1 opens the modal, nothing else.
