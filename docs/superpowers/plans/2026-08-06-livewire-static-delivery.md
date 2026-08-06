# Livewire Static Runtime Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve Livewire's runtime and hashed Vite assets as Brotli-compressed, immutable static files without breaking package-version cache invalidation.

**Architecture:** Livewire's supported `livewire:publish --assets` command writes its distribution and manifest to `public/vendor/livewire`; `@livewireScripts` then selects the manifest-versioned static URL automatically. A small application service derives the matching preload URL for the app and embedded layouts. The checked-in Nginx site config applies one immutable-cache policy to the Vite and published Livewire directories, while the deployment workflow publishes assets and safely reloads validated Nginx configuration.

**Tech Stack:** Laravel 11, Livewire 4.2, Blade, Pest, Nginx with Brotli and gzip, GitHub Actions.

## Global Constraints

- Implement FLTR-2416 and FLTR-2421 in this plan; do not change FLTR-2419 schedule aggregation.
- Use Livewire's `livewire:publish --assets` command; do not copy package runtime files into source control.
- Preserve Livewire's manifest version query parameter for deploy cache busting.
- Apply `public, max-age=31536000, immutable` only to `/build/assets/` and `/vendor/livewire/`.
- Require Nginx Brotli modules and fail a deploy before reload if `nginx -t` fails.
- Do not commit, push, deploy, or modify production infrastructure without separate user authorization.

---

### Task 1: Resolve and Preload the Published Runtime

**Files:**
- Create: `app/Support/LivewireRuntimeAsset.php`
- Create: `tests/Feature/LivewireRuntimeAssetTest.php`
- Modify: `resources/views/layouts/app.blade.php:20-22`
- Modify: `resources/views/layouts/embed.blade.php:9-11`

**Interfaces:**
- Consumes: `public/vendor/livewire/manifest.json` with a `'/livewire.js'` version key and `config('app.debug')`, `config('livewire.csp_safe')`.
- Produces: `App\Support\LivewireRuntimeAsset::preloadUrl(): ?string`, returning a relative static URL such as `/vendor/livewire/livewire.min.js?id=abc123`, or `null` when no published manifest exists.

- [ ] **Step 1: Write the failing service and layout test**

```php
use App\Support\LivewireRuntimeAsset;

test('returns the published production runtime preload URL', function () {
    $asset = new LivewireRuntimeAsset(base_path('tests/fixtures/livewire-runtime'));

    expect($asset->preloadUrl())
        ->toBe('/vendor/livewire/livewire.min.js?id=runtime-version');
});

test('app and embed layouts preload the published runtime', function () {
    // Create public/vendor/livewire/manifest.json with /livewire.js => runtime-version.
    // Render each layout and assert the exact same preload URL is present once.
});
```

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `php artisan test tests/Feature/LivewireRuntimeAssetTest.php`

Expected: FAIL because `LivewireRuntimeAsset` does not exist and neither layout emits the preload link.

- [ ] **Step 3: Implement manifest-backed URL resolution**

```php
final class LivewireRuntimeAsset
{
    public function __construct(private readonly string $publicPath) {}

    public function preloadUrl(): ?string
    {
        $manifest = $this->publicPath.'/vendor/livewire/manifest.json';

        if (! is_file($manifest)) return null;

        $version = json_decode((string) file_get_contents($manifest), true)['/livewire.js'] ?? null;

        if (! is_string($version) || $version === '') return null;

        $file = config('app.debug')
            ? (config('livewire.csp_safe') ? 'livewire.csp.js' : 'livewire.js')
            : (config('livewire.csp_safe') ? 'livewire.csp.min.js' : 'livewire.min.js');

        return "/vendor/livewire/{$file}?id={$version}";
    }
}
```

In each layout, resolve the service once and conditionally output:

```blade
@if ($livewireRuntimePreload = app(\App\Support\LivewireRuntimeAsset::class)->preloadUrl())
    <link rel="preload" href="{{ $livewireRuntimePreload }}" as="script">
@endif
```

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `php artisan test tests/Feature/LivewireRuntimeAssetTest.php`

Expected: PASS, including the missing-manifest case and both layouts' matching preload URL.

- [ ] **Step 5: Format the changed PHP files**

Run: `./vendor/bin/pint app/Support/LivewireRuntimeAsset.php tests/Feature/LivewireRuntimeAssetTest.php`

Expected: exit code 0.

### Task 2: Version-Control Static Asset Caching and Compression

**Files:**
- Create: `deploy/nginx/internal.filter.agency.conf`
- Create: `deploy/nginx/README.md`
- Modify: `.github/workflows/deploy.yml:74-103`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: build output in `public/build/assets/`, generated Livewire output in `public/vendor/livewire/`, and the existing production paths `/var/www/internal.filter.agency` and `/etc/nginx/sites-available/internal.filter.agency`.
- Produces: an Nginx configuration with `brotli on`, gzip fallback, `Vary: Accept-Encoding`, immutable caching only for static hashed directories, and a deployment sequence that publishes assets before Nginx validation/reload.

- [ ] **Step 1: Write the deployment configuration contract**

Create `deploy/nginx/README.md` with the exact production prerequisites and smoke commands:

```bash
sudo apt install -y libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static
sudo visudo -f /etc/sudoers.d/internal-tools-deploy
curl --compressed -I https://internal.filter.agency/vendor/livewire/livewire.min.js?id=<manifest-hash>
curl --compressed -I https://internal.filter.agency/build/assets/<hashed-file>.js
```

The sudoers entry must allow only the checked-in site-config install, `nginx -t`, and `systemctl reload nginx` without a password. Document that the commands must show `Content-Encoding: br` for Brotli-capable requests and `Cache-Control: public, max-age=31536000, immutable` for the two static directories.

- [ ] **Step 2: Add the production Nginx configuration**

Create `deploy/nginx/internal.filter.agency.conf` by preserving the existing TLS, PHP-FPM, and Laravel `try_files` behavior, then add:

```nginx
brotli on;
brotli_comp_level 5;
brotli_types text/css application/javascript application/json image/svg+xml;

gzip on;
gzip_types text/css application/javascript application/json image/svg+xml;

location ^~ /build/assets/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    add_header Vary "Accept-Encoding" always;
}

location ^~ /vendor/livewire/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    add_header Vary "Accept-Encoding" always;
}
```

- [ ] **Step 3: Update deployment sequencing**

Add the following after `npm ci && npm run build` and before Laravel caches are rebuilt:

```bash
php artisan livewire:publish --assets
sudo -n install -o root -g root -m 0644 /var/www/internal.filter.agency/deploy/nginx/internal.filter.agency.conf /etc/nginx/sites-available/internal.filter.agency
sudo -n nginx -t
sudo -n systemctl reload nginx
```

The commands deliberately fail the deploy if the production Brotli module or scoped sudo permission is missing; do not use `|| true`.

- [ ] **Step 4: Run local structural checks**

Run: `git diff --check && rg -n "livewire:publish --assets|brotli on|/build/assets/|/vendor/livewire/|immutable" .github/workflows/deploy.yml deploy/nginx`

Expected: exit code 0 and exactly the deployment, compression, and cache policy directives described above.

### Task 3: Verify Generated Assets and Browser Behavior

**Files:**
- Modify: `tests/Feature/LivewireRuntimeAssetTest.php` only if verification exposes a missing assertion.

**Interfaces:**
- Consumes: Task 1's preload URL, Task 2's deploy command, and Livewire's generated `public/vendor/livewire/manifest.json`.
- Produces: evidence that the production response references a static versioned runtime and that application navigation still works.

- [ ] **Step 1: Publish Livewire assets locally**

Run: `php artisan livewire:publish --assets`

Expected: `public/vendor/livewire/manifest.json` and the selected runtime file exist; they remain ignored/untracked generated output.

- [ ] **Step 2: Run focused and complete automated checks**

Run: `php artisan test tests/Feature/LivewireRuntimeAssetTest.php && php artisan test && npm run build && node --test tests/Node/*.test.mjs`

Expected: all tests pass and Vite emits content-hashed build assets.

- [ ] **Step 3: Run local browser QA**

Run the app, sign in, and inspect the Timesheet document head and network requests.

Expected: the preload URL and `@livewireScripts` URL match `/vendor/livewire/livewire.min.js?id=<manifest-hash>`; visiting Timesheet and navigating to Schedule still produces no browser-console errors.

- [ ] **Step 4: Record production-only verification**

After an authorized production deployment, run the README's two `curl --compressed -I` commands and a cold/repeat browser profile.

Expected: both assets return immutable cache headers; Brotli is selected for compatible clients; the Livewire request is static rather than a PHP route; repeat loads do not revalidate hashed Vite files.
