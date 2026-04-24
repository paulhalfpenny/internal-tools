---
title: Production Deployment — internal.filter.agency
date: 2026-04-24
status: approved
---

# Production Deployment Design

## Overview

Deploy the `internal-tools` Laravel 11 application to a new DigitalOcean droplet at `internal.filter.agency`. Manual server setup (no Laravel Forge), with GitHub Actions CI/CD for automatic deploys on push to `main`.

---

## Server Stack

| Component | Choice |
|---|---|
| OS | Ubuntu 22.04 LTS |
| Web server | Nginx |
| PHP | 8.2 + PHP-FPM (Unix socket) |
| Database | MySQL 8.0 (same droplet) |
| SSL | Certbot + Let's Encrypt (systemd auto-renewal) |
| Node.js | v20 LTS (for `npm run build` during deploy) |
| Process manager | systemd |

- App files live at `/var/www/internal.filter.agency/`, owned by a dedicated `deploy` user (non-root)
- Nginx runs as `www-data` with read access to the webroot
- MySQL has a dedicated `internal_tools` database and user — no root DB access from the app
- PHP-FPM listens on a Unix socket (`/run/php/php8.2-fpm.sock`)

---

## CI/CD Pipeline

**Trigger:** push to `main` branch on GitHub.

**GitHub Actions secrets required:**

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | Droplet IP address |
| `DEPLOY_SSH_KEY` | Private key for the `deploy` user (Actions runner uses this to SSH in) |

**Deploy flow:**

1. GitHub Actions SSHes into the droplet as `deploy`
2. Runs the deploy script on the server:

```bash
cd /var/www/internal.filter.agency
git pull origin main
composer install --no-dev --no-interaction --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Keys:**
- The `deploy` user has a **read-only deploy key** registered on the GitHub repo (for `git pull`)
- The GitHub Actions runner uses a **separate SSH keypair** stored only in GitHub Secrets — never committed to the repo
- No queue restart step (no queue workers running)

---

## Nginx Configuration

- HTTP (port 80) redirects to HTTPS (port 443)
- HTTPS served with Certbot-managed Let's Encrypt certificates
- Webroot: `/var/www/internal.filter.agency/public`
- `try_files` routing for Laravel (`$uri`, `$uri/`, `index.php`)
- `client_max_body_size 20M` (supports Harvest CSV imports)

---

## Environment & Secrets

`.env` is created manually on first deploy at `/var/www/internal.filter.agency/.env` and is never committed to git. It persists across deploys (`git pull` does not overwrite it).

Key production values:

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://internal.filter.agency` |
| `DB_HOST` | `127.0.0.1` |
| `DB_DATABASE` | `internal_tools` |
| `DB_USERNAME` | `internal_tools` |
| `DB_PASSWORD` | *(strong generated password)* |
| `DB_SOCKET` | *(removed — MAMP-only, not set in production)* |
| `APP_KEY` | *(generated on first deploy via `php artisan key:generate`)* |
| `GOOGLE_REDIRECT_URI` | `https://internal.filter.agency/auth/google/callback` |

`storage/` and `bootstrap/cache/` are writable by `deploy` user and `www-data` group — set once on server setup.

Google OAuth app redirect URI must be updated to `https://internal.filter.agency/auth/google/callback` before first login.

---

## Backups

Relying entirely on DigitalOcean droplet snapshots (daily). This covers the full server state: OS, MySQL data directory, app files, and `.env`.

Spatie Laravel Backup remains as a Composer dependency but is **disabled** — no scheduled backup commands are registered. No DO Spaces bucket or S3 credentials are needed.

---

## Out of Scope

- Queue workers (none needed currently)
- Staging environment (not requested)
- Laravel Horizon, Pulse, or Telescope in production
- Sentry error reporting (can be added later)
