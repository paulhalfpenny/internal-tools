# Production Nginx Setup

`internal.filter.agency.conf` is the authoritative Nginx site configuration
for Internal Tools. The deployment workflow installs it, validates the full
Nginx configuration, then reloads Nginx.

## One-time prerequisites

Install the Brotli modules on the production server before the first deploy
that includes this configuration:

```bash
sudo apt update
sudo apt install -y libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static
```

Grant the `deploy` user passwordless access only to install this site
configuration, validate Nginx, and reload it. Create the sudoers file with
`sudo visudo -f /etc/sudoers.d/internal-tools-deploy` and add:

```sudoers
deploy ALL=(root) NOPASSWD: /usr/bin/install -o root -g root -m 0644 /var/www/internal.filter.agency/deploy/nginx/internal.filter.agency.conf /etc/nginx/sites-available/internal.filter.agency, /usr/sbin/nginx -t, /usr/bin/systemctl reload nginx
```

The exact command locations can differ by distribution. Confirm them with
`command -v install nginx systemctl` before saving the sudoers entry.

## Post-deploy checks

Use the Livewire manifest value and a current Vite manifest filename:

```bash
curl --compressed -I 'https://internal.filter.agency/vendor/livewire/livewire.min.js?id=<manifest-hash>'
curl --compressed -I 'https://internal.filter.agency/build/assets/<hashed-file>.js'
```

For Brotli-capable clients, both responses must include
`Content-Encoding: br`, `Vary: Accept-Encoding`, and
`Cache-Control: public, max-age=31536000, immutable`. HTML and non-versioned
assets must not receive this immutable policy.
