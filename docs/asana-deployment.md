# Asana integration — production deployment

Checklist for getting the Asana integration running on the production
DigitalOcean box, and what to do on every subsequent deploy.

## One-time setup

Do this once, before the first deploy that includes the Asana code.

### 1. Register the Asana OAuth app

Visit https://app.asana.com/0/my-apps → **My apps** → **New app**. Add the
production redirect URI:

```
https://<your-production-domain>/integrations/asana/callback
```

Copy the **Client ID** and **Client Secret** for the next step.

### 2. Add Asana env vars to production `.env`

SSH onto the box and edit `.env`:

```
ASANA_CLIENT_ID=<from step 1>
ASANA_CLIENT_SECRET=<from step 1>
ASANA_REDIRECT_URI="${APP_URL}/integrations/asana/callback"
ASANA_HOURS_FIELD_NAME="Hours tracked (Internal Tools)"
```

### 3. Install Supervisor and create the queue worker

The integration is queue-driven. Without a persistent worker, jobs sit in
the `jobs` table and never run.

```bash
sudo apt update && sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/internal-tools-worker.conf
```

Paste (replace `<deploy-user>` and the app path if different):

```ini
[program:internal-tools-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/internal-tools/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=<deploy-user>
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/internal-tools-worker.log
stopwaitsecs=3600
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start internal-tools-worker:*
sudo supervisorctl status   # confirm RUNNING
```

`--max-time=3600` cycles the worker every hour so memory leaks get
reclaimed and post-deploy code gets picked up after `queue:restart`.

### 4. Add the scheduler cron line

The hourly Asana task refresh, daily project refresh, and daily log prune
all rely on Laravel's scheduler. The scheduler itself runs from cron.

```bash
sudo crontab -u <deploy-user> -e
```

Append:

```
* * * * * cd /var/www/internal-tools && php artisan schedule:run >> /dev/null 2>&1
```

## Every deploy

After `git pull` (or your CI's equivalent):

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

`queue:restart` is critical — without it, Supervisor's worker keeps
running pre-deploy code until its `--max-time` cycle ends.

## First-use after the first Asana-aware deploy

1. Sign in as an **admin** and visit `/profile/asana` → click **Connect
   Asana** and complete the OAuth flow.
2. Visit `/admin/integrations/asana` and click **Pull projects from my
   workspace** (or wait until 06:00 for the daily refresh).
3. On any project's edit page (`/admin/projects/{id}/edit`), pick the
   linked Asana project from the new dropdown and **Save**. The
   cumulative-hours custom field is created on the Asana project
   automatically.
4. Log a tracked-time entry on that project and confirm — within seconds
   — that the Asana task's "Hours tracked (Internal Tools)" custom field
   updates.

## Verification

- `/admin/integrations/asana` → "Pending Asana jobs" sits at 0 in steady
  state; no yellow banner appears.
- `sudo supervisorctl status internal-tools-worker:*` → `RUNNING`.
- `tail -f /var/log/internal-tools-worker.log` → jobs being processed.
- `php artisan schedule:list` → shows the three Asana scheduled commands
  with a sensible "Next Due" time.
- After ~1 hour: `asana.pull_tasks.completed` entries appear in the
  recent sync log on the admin page.
- After 24h: `asana.pull_projects.completed` entries appear too.

## Schedule reference

| Command                  | When               | What it does                              |
| ------------------------ | ------------------ | ----------------------------------------- |
| `asana:refresh-projects` | daily, 06:00       | Re-pulls Asana project lists per workspace |
| `asana:refresh-tasks`    | hourly             | Re-pulls tasks for every linked project    |
| `asana:prune-logs`       | daily, 03:00       | Deletes `asana_sync_logs` older than 30 days |

## Rollback

All five Asana migrations have proper `down()` methods. To undo the
schema:

```bash
php artisan migrate:rollback --step=5
```

The integration code is dormant once env vars are removed — the OAuth
controller short-circuits with "not configured" and the time-entry
modal stays in its pre-Asana shape because no project will be linked.
So partial rollback is safe: you can clear the env vars and leave the
schema in place to disable the feature without code changes.

## Related runbook bits

If you also need to re-pull a single Asana project's tasks on demand (e.g.
after someone renames an Asana task and a user can't find it in the
picker), an admin can:

1. Open `/admin/projects/{id}/edit`.
2. Click **Refresh tasks** in the Asana section.

Or trigger the global refresh via SSH:

```bash
php artisan asana:refresh-tasks
```
