# Filter Internal Tools — Asana extension

Adds a **Log time** button to Asana's task toolbar. Clicking it opens a
small popup window containing the timesheet's prefilled entry form for the
task (mapped project, remembered task, Asana link set). The popup keeps the
Asana task visible behind it and avoids Asana's Content Security Policy,
which blocks unlisted third-party iframe sources.

Spec: [docs/superpowers/specs/2026-07-03-asana-browser-extension-design.md](../docs/superpowers/specs/2026-07-03-asana-browser-extension-design.md)

## Install (developer / testing)

1. Chrome or Edge → `chrome://extensions` (or `edge://extensions`).
2. Enable **Developer mode** (top right).
3. **Load unpacked** → select this `asana-extension/` directory.
4. Open any Asana task — the Log time button appears in the task toolbar.

## Team rollout (Chrome Web Store, unlisted)

One-time setup (Paul):

1. Go to https://chrome.google.com/webstore/devconsole, sign in with
   paul@filteragency.com, and pay the one-time $5 developer registration.
2. **New item** → upload the packaged zip (see Packaging below).
3. Store listing: category *Productivity*, language *English*, add at
   least one 1280×800 screenshot of the dialog open on an Asana task.
   Icon comes from the manifest.
4. Privacy tab:
   - Single purpose: "Log time to Filter Internal Tools from Asana tasks."
   - `app.asana.com` content script: injects the Log time button on tasks.
   - `internal.filter.agency` host permission: polls the signed-in user's
     running-timer status so the toolbar icon can show a running cue.
   - Data usage: no data collected, sold, or transferred; auth is the
     user's existing Internal Tools session cookie.
5. Distribution: visibility **Unlisted** → submit for review (typically
   hours to a couple of days).
6. Share the install link with the team (works in Chrome; Edge users can
   install from the Chrome Web Store after accepting the prompt).

Updating: bump `version` in `manifest.json`, rebuild the zip, upload it in
the developer console, submit. Chrome checks for extension updates roughly
every five hours, so installs pick it up automatically in the background —
nobody has to do anything. (chrome://extensions → *Update* forces it.)

## Packaging

```sh
cd asana-extension
zip ../asana-extension-<version>.zip manifest.json content.js background.js icon-16.png icon-48.png icon-128.png
```

The zip must contain `manifest.json` at its root (it does — no folder
wrapper). Zips are gitignored.

## When it breaks

The button is injected into Asana's DOM, which they change without notice.
Failure mode is silent (no button); nothing else is affected. Selectors live
in one place — `findAnchor()` in `content.js` — anchored on the "Like this
task" icon's aria-label, with the "Mark complete" button as fallback.
Fix by inspecting the task pane in devtools and updating those anchors.

## Notes

- Runs on `app.asana.com` plus a host permission for
  `internal.filter.agency`: the background worker polls
  `/asana-app/timer-status` (session cookie included) so the toolbar icon
  can show a running-timer cue — green stopwatch for a timer on the open
  task, green corner dot for a timer on another task.
- The toolbar icon inherits Asana's current theme color so it remains
  visible in both light and dark mode, including while hovered.
- Auth uses the user's normal Internal Tools session in a top-level popup;
  the entry form is opened through `/timesheet?log_asana={gid}` and does
  not depend on cross-origin iframe access.
- Task id detection prefers the visible task pane's `data-task-id`, which
  works in project lists, My Tasks and Inbox, then falls back to current
  (`/task/{gid}`) and legacy (`/0/{project}/{gid}`) URL shapes plus the
  `?task=` query param.
