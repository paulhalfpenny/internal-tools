# Filter Internal Tools — Asana extension

Adds a **Log time** button to Asana's task toolbar. Clicking it opens a
native `<dialog>` (Harvest-style) hosting the compact log-time form,
prefilled for the task (mapped project, remembered task, Asana link set).
The dialog sizes itself to the form (the embed page reports its height
over postMessage), closes on save/Cancel/Esc/backdrop click, and the
form's "My timesheet" link is the fallback if login-in-iframe is ever
blocked.

Spec: [docs/superpowers/specs/2026-07-03-asana-browser-extension-design.md](../docs/superpowers/specs/2026-07-03-asana-browser-extension-design.md)

## Install (developer / testing)

1. Chrome or Edge → `chrome://extensions` (or `edge://extensions`).
2. Enable **Developer mode** (top right).
3. **Load unpacked** → select this `asana-extension/` directory.
4. Open any Asana task — the Log time button appears in the task toolbar.

## Team rollout

Publish to the Chrome Web Store as **unlisted** and share the install link
(covers Edge too). Update by bumping `version` in `manifest.json` and
re-uploading — installs update automatically.

## When it breaks

The button is injected into Asana's DOM, which they change without notice.
Failure mode is silent (no button); nothing else is affected. Selectors live
in one place — `findAnchor()` in `content.js` — anchored on the "Like this
task" icon's aria-label, with the "Mark complete" button as fallback.
Fix by inspecting the task pane in devtools and updating those anchors.

## Notes

- No permissions beyond running on `app.asana.com`; no storage, no network
  requests from the extension itself. Auth is the user's normal Internal
  Tools session inside the overlay iframe — this depends on the app sending
  `SameSite=None` session cookies and a `frame-ancestors` policy that
  allows `app.asana.com` (see `RestrictFrameAncestors` middleware).
- Task id detection handles current (`/task/{gid}`) and legacy
  (`/0/{project}/{gid}`) URL shapes plus the `?task=` query param.
