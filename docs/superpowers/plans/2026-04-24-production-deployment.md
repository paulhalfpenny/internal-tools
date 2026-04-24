# Production Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy the internal-tools Laravel 11 app to a DigitalOcean droplet at `internal.filter.agency` with Nginx, PHP-FPM, MySQL, Certbot SSL, and GitHub Actions CI/CD.

**Architecture:** Manual Ubuntu 22.04 server setup with a dedicated `deploy` user. GitHub Actions SSHes in on push to `main` and runs a deploy script. The server pulls from GitHub using a read-only deploy key.

**Tech Stack:** Ubuntu 22.04 LTS, Nginx, PHP 8.2 + PHP-FPM, MySQL 8.0, Node.js 20 LTS, Certbot, GitHub Actions

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `.github/workflows/deploy.yml` | Create | CD workflow — SSH deploy on push to `main` |
| `.env.example` | Modify | Update stale app name, DB name, remove MAMP socket comment |

All other changes are server-side (SSH commands on the droplet) — not in the repo.

---

## Task 1: Update `.env.example`

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Update APP_NAME**

In `.env.example`, change:
```
APP_NAME="Filter Time Tracker"
```
to:
```
APP_NAME="Filter Internal Tools"
```

- [ ] **Step 2: Update DB_DATABASE**

Change:
```
DB_DATABASE=time_tracking
```
to:
```
DB_DATABASE=internal_tools
```

- [ ] **Step 3: Remove MAMP socket comment**

Remove the line:
```
# DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock  # uncomment if using MAMP
```

- [ ] **Step 4: Update DO_SPACES_BUCKET**

Change (this key is unused in production but keeps the example accurate):
```
DO_SPACES_BUCKET=filter-time-tracker-backups
```
to:
```
DO_SPACES_BUCKET=filter-internal-tools-backups
```

- [ ] **Step 5: Commit**

```bash
git add .env.example
git commit -m "chore: update .env.example for internal-tools rename"
```

---

## Task 2: Create GitHub Actions Deploy Workflow

**Files:**
- Create: `.github/workflows/deploy.yml`

- [ ] **Step 1: Create the workflow file**

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Deploy to production
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ secrets.DEPLOY_HOST }}
          username: deploy
          key: ${{ secrets.DEPLOY_SSH_KEY }}
          script: |
            set -e
            export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"
            cd /var/www/internal.filter.agency
            git pull origin main
            composer install --no-dev --no-interaction --optimize-autoloader
            npm ci && npm run build
            php artisan migrate --force
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
```

- [ ] **Step 2: Verify the workflow file is valid YAML**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))" && echo "Valid YAML"
```
Expected: `Valid YAML`

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "ci: add GitHub Actions deploy workflow for internal.filter.agency"
```

- [ ] **Step 4: Push to main**

```bash
git push origin main
```

Note: The deploy will fail at this point (server not yet set up) — that's expected. Confirm the Actions run appears in GitHub under the Actions tab.

---

## Task 3: Initial Server Setup

All steps in this task run over SSH as `root` on the new droplet.

```bash
ssh root@<DROPLET_IP>
```

- [ ] **Step 1: Update the system**

```bash
apt update && apt upgrade -y
```

- [ ] **Step 2: Create the `deploy` user**

```bash
adduser --disabled-password --gecos "" deploy
usermod -aG www-data deploy
```

- [ ] **Step 3: Generate two SSH keypairs for the deploy user**

Run on your **local machine** (not the server):

```bash
# Key 1: for GitHub Actions runner to SSH into the server
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/internal_tools_actions_deploy -N ""

# Key 2: for the server to git pull from GitHub (read-only deploy key)
ssh-keygen -t ed25519 -C "server-github-deploy-key" -f ~/.ssh/internal_tools_server_github -N ""
```

- [ ] **Step 4: Authorise the Actions runner key on the server**

```bash
# On the server, as root:
mkdir -p /home/deploy/.ssh
echo "<PASTE CONTENTS OF ~/.ssh/internal_tools_actions_deploy.pub>" >> /home/deploy/.ssh/authorized_keys
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
```

- [ ] **Step 5: Install the server's GitHub key**

```bash
# On the server, as root:
mkdir -p /home/deploy/.ssh
cat > /home/deploy/.ssh/id_ed25519 << 'EOF'
<PASTE CONTENTS OF ~/.ssh/internal_tools_server_github (private key)>
EOF
chmod 600 /home/deploy/.ssh/id_ed25519
chown deploy:deploy /home/deploy/.ssh/id_ed25519
```

- [ ] **Step 6: Verify SSH access as deploy**

```bash
# From your local machine:
ssh deploy@<DROPLET_IP> "echo SSH OK"
```
Expected: `SSH OK`

---

## Task 4: Install PHP 8.2 + PHP-FPM

SSH in as root.

- [ ] **Step 1: Add the Ondřej Surý PHP PPA**

```bash
apt install -y software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update
```

- [ ] **Step 2: Install PHP 8.2 and required extensions**

```bash
apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
  php8.2-xml php8.2-zip php8.2-curl php8.2-intl php8.2-bcmath \
  php8.2-tokenizer php8.2-gd
```

- [ ] **Step 3: Verify PHP version**

```bash
php8.2 -v
```
Expected: `PHP 8.2.x ...`

- [ ] **Step 4: Verify PHP-FPM socket exists**

```bash
systemctl status php8.2-fpm
ls /run/php/php8.2-fpm.sock
```
Expected: service active, socket file present.

---

## Task 5: Install MySQL 8.0 and Create Database

- [ ] **Step 1: Install MySQL**

```bash
apt install -y mysql-server
systemctl enable mysql
systemctl start mysql
```

- [ ] **Step 2: Secure MySQL installation**

```bash
mysql_secure_installation
```
Follow prompts: set a strong root password, remove anonymous users, disallow remote root login, remove test database, reload privilege tables.

- [ ] **Step 3: Create database and user**

```bash
mysql -u root -p
```

Inside the MySQL prompt:
```sql
CREATE DATABASE internal_tools CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'internal_tools'@'127.0.0.1' IDENTIFIED BY '<STRONG_PASSWORD>';
GRANT ALL PRIVILEGES ON internal_tools.* TO 'internal_tools'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```
Replace `<STRONG_PASSWORD>` with a randomly generated password (e.g. `openssl rand -base64 32`). Save it — you'll need it in the `.env`.

- [ ] **Step 4: Verify connection**

```bash
mysql -u internal_tools -p'<STRONG_PASSWORD>' -h 127.0.0.1 -e "SHOW DATABASES;"
```
Expected: `internal_tools` appears in the list.

---

## Task 6: Install Nginx

- [ ] **Step 1: Install Nginx**

```bash
apt install -y nginx
systemctl enable nginx
systemctl start nginx
```

- [ ] **Step 2: Verify Nginx is running**

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost
```
Expected: `200`

---

## Task 7: Install Node.js 20 LTS

- [ ] **Step 1: Install via NodeSource**

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

- [ ] **Step 2: Verify versions**

```bash
node -v && npm -v
```
Expected: `v20.x.x` and `10.x.x` (or similar).

---

## Task 8: Install Composer

- [ ] **Step 1: Download and install Composer**

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

- [ ] **Step 2: Verify**

```bash
composer --version
```
Expected: `Composer version 2.x.x`

---

## Task 9: Clone Repo and Set Permissions

- [ ] **Step 1: Register the server's GitHub deploy key on the repo**

In GitHub → repo → **Settings → Deploy keys → Add deploy key**:
- Title: `internal-tools production server`
- Key: paste contents of `~/.ssh/internal_tools_server_github.pub`
- Allow write access: **No** (read-only)

- [ ] **Step 2: Test GitHub connectivity from the server**

```bash
# On the server, as deploy user:
su - deploy
ssh -T git@github.com
```
Expected: `Hi <username>! You've successfully authenticated...`

- [ ] **Step 3: Create webroot and clone the repo**

```bash
# As root:
mkdir -p /var/www/internal.filter.agency
chown deploy:www-data /var/www/internal.filter.agency
chmod 2775 /var/www/internal.filter.agency

# As deploy user:
su - deploy
git clone git@github.com:<ORG>/internal-tools.git /var/www/internal.filter.agency
```
Replace `<ORG>` with the GitHub organisation or username.

- [ ] **Step 4: Set storage and cache permissions**

```bash
# As root:
chown -R deploy:www-data /var/www/internal.filter.agency/storage
chown -R deploy:www-data /var/www/internal.filter.agency/bootstrap/cache
chmod -R 775 /var/www/internal.filter.agency/storage
chmod -R 775 /var/www/internal.filter.agency/bootstrap/cache
```

---

## Task 10: Create `.env` and Install App

- [ ] **Step 1: Create the production `.env`**

```bash
# As deploy user:
cp /var/www/internal.filter.agency/.env.example /var/www/internal.filter.agency/.env
```

Edit `/var/www/internal.filter.agency/.env` with these production values:
```dotenv
APP_NAME="Filter Internal Tools"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=Europe/London
APP_URL=https://internal.filter.agency

LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=internal_tools
DB_USERNAME=internal_tools
DB_PASSWORD=<STRONG_PASSWORD>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@filter.agency"
MAIL_FROM_NAME="Filter Internal Tools"

GOOGLE_CLIENT_ID=<FROM_GOOGLE_CONSOLE>
GOOGLE_CLIENT_SECRET=<FROM_GOOGLE_CONSOLE>
GOOGLE_REDIRECT_URI=https://internal.filter.agency/auth/google/callback

ADMIN_EMAIL=paul@filter.agency
VITE_APP_NAME="${APP_NAME}"
```

Leave `APP_KEY=` blank — it will be generated in the next step.

- [ ] **Step 2: Install Composer dependencies**

```bash
# As deploy user:
cd /var/www/internal.filter.agency
composer install --no-dev --no-interaction --optimize-autoloader
```

- [ ] **Step 3: Generate APP_KEY**

```bash
php artisan key:generate
```
Expected: `Application key set successfully.`

- [ ] **Step 4: Build frontend assets**

```bash
npm ci && npm run build
```
Expected: Vite build completes with no errors.

- [ ] **Step 5: Run migrations**

```bash
php artisan migrate --force
```
Expected: Migrations run successfully.

- [ ] **Step 6: Cache config, routes, views**

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Task 11: Configure Nginx Virtual Host

- [ ] **Step 1: Create the Nginx site config**

Create `/etc/nginx/sites-available/internal.filter.agency`:

```nginx
server {
    listen 80;
    server_name internal.filter.agency;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name internal.filter.agency;

    root /var/www/internal.filter.agency/public;
    index index.php;

    client_max_body_size 20M;

    ssl_certificate /etc/letsencrypt/live/internal.filter.agency/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/internal.filter.agency/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Note: SSL paths reference Let's Encrypt — Certbot will create these in Task 12. For now, create the file with these contents; Nginx will not be reloaded until after Certbot runs.

- [ ] **Step 2: Enable the site**

```bash
ln -s /etc/nginx/sites-available/internal.filter.agency /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
```

- [ ] **Step 3: Test Nginx config syntax**

```bash
nginx -t
```
Expected: `syntax is ok` and `test is successful` — note: this will warn about missing SSL cert files, which is expected before Certbot runs.

---

## Task 12: SSL with Certbot

> DNS for `internal.filter.agency` must be pointing to the droplet IP before running this task.

- [ ] **Step 1: Verify DNS resolves to the droplet**

```bash
dig +short internal.filter.agency
```
Expected: the droplet's IP address.

- [ ] **Step 2: Install Certbot**

```bash
apt install -y certbot python3-certbot-nginx
```

- [ ] **Step 3: Obtain certificate**

Temporarily use the HTTP-only config for Certbot's webroot challenge. First, replace the Nginx config with a temporary HTTP-only version:

```bash
cat > /etc/nginx/sites-available/internal.filter.agency << 'EOF'
server {
    listen 80;
    server_name internal.filter.agency;
    root /var/www/internal.filter.agency/public;

    location ~ /\.well-known/acme-challenge {
        allow all;
    }
}
EOF
nginx -t && systemctl reload nginx
```

Then run Certbot:
```bash
certbot --nginx -d internal.filter.agency --non-interactive --agree-tos -m paul@filter.agency
```
Expected: `Successfully received certificate.`

- [ ] **Step 4: Restore the full Nginx config with SSL**

```bash
cat > /etc/nginx/sites-available/internal.filter.agency << 'EOF'
server {
    listen 80;
    server_name internal.filter.agency;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name internal.filter.agency;

    root /var/www/internal.filter.agency/public;
    index index.php;

    client_max_body_size 20M;

    ssl_certificate /etc/letsencrypt/live/internal.filter.agency/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/internal.filter.agency/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
nginx -t && systemctl reload nginx
```

- [ ] **Step 5: Verify HTTPS is live**

```bash
curl -s -o /dev/null -w "%{http_code}" https://internal.filter.agency
```
Expected: `200` (or `302` redirect to login).

- [ ] **Step 6: Verify auto-renewal timer**

```bash
systemctl status certbot.timer
```
Expected: `active (waiting)`.

---

## Task 13: Wire Up GitHub Actions Secrets

- [ ] **Step 1: Add secrets to GitHub**

In GitHub → repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | The droplet's IP address |
| `DEPLOY_SSH_KEY` | Full contents of `~/.ssh/internal_tools_actions_deploy` (private key) |

- [ ] **Step 2: Trigger a deploy by pushing a trivial commit**

```bash
git commit --allow-empty -m "ci: trigger first production deploy"
git push origin main
```

- [ ] **Step 3: Watch the Actions run**

In GitHub → repo → **Actions tab** → watch the `Deploy` workflow run.
Expected: all steps green, deploy script completes without error.

- [ ] **Step 4: Smoke test the site**

```bash
curl -s -o /dev/null -w "%{http_code}" https://internal.filter.agency
```
Expected: `200` or `302`.

Open `https://internal.filter.agency` in a browser — you should see the login page.

---

## Task 14: Update Google OAuth Redirect URI

- [ ] **Step 1: Open Google Cloud Console**

Go to **APIs & Services → Credentials** → click the OAuth 2.0 Client ID used for this app.

- [ ] **Step 2: Add the new redirect URI**

Under **Authorised redirect URIs**, add:
```
https://internal.filter.agency/auth/google/callback
```
Save.

- [ ] **Step 3: Test Google login**

Open `https://internal.filter.agency` in a browser, click **Sign in with Google**, complete the OAuth flow.
Expected: successful login, redirected to the app dashboard.

---

## Task 15: Post-Deploy Verification

- [ ] **Step 1: Check Laravel logs are clean**

```bash
# On server as deploy:
tail -50 /var/www/internal.filter.agency/storage/logs/laravel.log
```
Expected: no `ERROR` or `CRITICAL` entries.

- [ ] **Step 2: Verify database session driver**

```bash
php artisan tinker --execute="echo DB::table('sessions')->count();"
```
Expected: a number (0 or more) without error — confirms the `sessions` table exists and the DB connection is healthy.

- [ ] **Step 3: Verify Certbot auto-renewal dry run**

```bash
certbot renew --dry-run
```
Expected: `Congratulations, all simulated renewals succeeded.`

- [ ] **Step 4: Update the RUNBOOK**

In `docs/RUNBOOK.md`, update the Environments table:

```markdown
| Production | https://internal.filter.agency | DigitalOcean droplet (2 vCPU / 4GB) |
```

And update the deploy section to reflect the new GitHub Actions process (no Forge).

- [ ] **Step 5: Commit RUNBOOK update**

```bash
git add docs/RUNBOOK.md
git commit -m "docs: update runbook for internal.filter.agency deployment"
git push origin main
```
