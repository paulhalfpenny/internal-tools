# Filter time logger — Freshdesk custom app

Lets a Freshdesk agent log time straight onto their internal-tools timesheet
from a ticket sidebar widget. No more switching tabs and copy-pasting ticket
URLs.

## Architecture

```
Freshdesk ticket page
  └── ticket_sidebar (this app)
        └── HTTPS → internal-tools API
              ├── /api/me              identify the agent
              ├── /api/projects        projects + tasks + Asana tasks
              ├── /api/time-entries    create an entry
              └── /api/timers/*        start / stop / read running timer
```

Auth is a **personal access token** the agent generates on their own profile
page in internal-tools (`/profile/api-tokens`) and pastes once into the widget.
The widget stores it in Freshdesk per-agent instance storage; nothing about
the integration is shared between agents.

## Files

| Path | Purpose |
|---|---|
| `manifest.json` | Declares this is a Freshdesk app, ticket_sidebar location, allowed domains |
| `config/iparams.json` | Account-level setting: internal-tools base URL |
| `config/requests.json` | Outbound API request templates (token injected per-call) |
| `app/index.html` | Widget markup |
| `app/scripts/app.js` | Widget logic |
| `app/styles/style.css` | Widget styles |
| `app/styles/icon.svg` | Sidebar icon |

## Local development

fdk requires Node **18.x.x** specifically. If your global Node is newer,
install Node 18 alongside via Homebrew and put it ahead on PATH for the
shell session:

```bash
brew install node@18
export PATH="/opt/homebrew/opt/node@18/bin:$PATH"
```

Or persist as a shell alias:

```bash
echo 'alias fdk='\''PATH="/opt/homebrew/opt/node@18/bin:$PATH" fdk'\''' >> ~/.zshrc
source ~/.zshrc
```

Then install the CLI and disable the global-apps feature flag (it requires
platform 3.0+; this app runs on 2.3):

```bash
npm install -g https://cdn.freshdev.io/fdk/latest.tgz
fdk config set global_apps.enabled false
```

Run the app locally:

```bash
fdk run
```

That serves the app at `http://localhost:10001`. Open any Freshdesk ticket
with `?dev=true` appended to its URL and Freshdesk will load this app from
your local server. Set the **Internal Tools URL** to your local
internal-tools dev URL (e.g. `http://127.0.0.1:8000`) on the install screen.

## Packaging for install

```bash
fdk validate
fdk pack --skip-coverage
```

`fdk pack` produces `dist/<account>.zip`. That zip is what the Freshdesk
admin uploads. The `--skip-coverage` flag bypasses the 80% test-coverage
gate that fdk enforces for Marketplace submissions; we're a private
custom app, so it doesn't apply.

## Installing in Freshdesk (custom app, not Marketplace)

1. **Admin → Apps → Manage Apps → Custom Apps**
2. Click **Upload custom app** and select the zip.
3. Set **Internal Tools URL** to the production URL of internal-tools, e.g.
   `https://internaltools.filteragency.com` (no trailing slash).
4. **Install for**: Account-wide.

That puts the clock icon on every ticket sidebar.

## First-time agent setup

Each agent does this once:

1. Open internal-tools and go to **Profile → API tokens**.
2. Click **Generate** (name it "Freshdesk widget"). Copy the token.
3. Open any Freshdesk ticket. Click the clock icon in the sidebar.
4. Paste the token into the widget and click **Connect**.

The token is now stored in Freshdesk against their agent ID and the widget
will skip straight to the log-time form on every future ticket.

## Behaviour notes

- **Default note** on each entry is `[#{ticket_id}] {ticket_subject} — {ticket_url}`
  — the agent can edit before saving.
- **Asana-linked projects** require an Asana task selection, same as the
  internal-tools day view.
- **Empty HH:MM** starts a timer; filled HH:MM saves an entry directly.
- **Running-timer banner** appears at the bottom if the agent has a timer
  running anywhere (e.g. they started one in the day view earlier). They can
  stop it from the widget without leaving the ticket.

## Out of scope for v1 (intentional)

- **Private-note write-back** — adding a private note to the Freshdesk ticket
  recording the time logged. Needs a separate Freshdesk admin API key. If
  this turns out to be useful, add an `addPrivateNote` template in
  `config/requests.json` plus an admin-key iparam, then wire up the
  conversation-create call (`POST /api/v2/tickets/{id}/notes`).
- **Marketplace listing** — this is a private custom app, not for public
  install.
- **Agent picker** — the widget always logs against the token holder. There's
  no "log time on behalf of someone else" path.

## Troubleshooting

- **"That token didn't work"**: check the token isn't revoked at
  `/profile/api-tokens` on internal-tools, and that the **Internal Tools URL**
  iparam is correct (no trailing slash).
- **No projects in dropdown**: the agent isn't assigned to any non-archived
  project on internal-tools. Have an admin assign them.
- **CORS errors during local dev**: the `whitelisted-domains` in
  `manifest.json` covers `*.filteragency.com` and localhost. Add other hosts
  there if needed and re-run `fdk run`.
