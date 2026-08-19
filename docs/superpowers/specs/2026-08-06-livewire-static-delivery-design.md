# Livewire Static Runtime Delivery Design

## Goal

Shorten first-load rendering by serving the Livewire runtime as a static,
versioned asset with Brotli compression and a head preload. At the same time,
give Vite's content-hashed JavaScript and CSS the same immutable cache policy.

This design implements FLTR-2416 and FLTR-2421 together. FLTR-2419 remains
separate because it changes schedule aggregation and time-entry invalidation.

## Runtime Asset

The deployment will run `php artisan livewire:publish --assets` after Composer
dependencies are installed. Livewire detects the published manifest and emits
its supported static runtime URL:

`/vendor/livewire/livewire.min.js?id=<manifest-hash>`

The manifest hash changes when the Livewire distribution changes, so a package
upgrade produces a distinct URL. Nginx serves this existing public file without
passing the request to PHP.

The app and embed layouts will emit a matching `rel=preload` link in the head.
The URL helper will use the published manifest and return no preload when the
asset is unavailable, preserving local development behavior.

## Production Web Server

Add the production site configuration under `deploy/nginx/` and make the
deployment workflow install it, validate it with `nginx -t`, then reload Nginx.
The production deploy user must have passwordless, narrowly scoped sudo access
for those actions.

The server configuration will:

- enable Brotli for JavaScript and CSS, retaining gzip as the fallback;
- set `Cache-Control: public, max-age=31536000, immutable` for
  `/build/assets/` and `/vendor/livewire/`;
- leave HTML, routes, and non-versioned public assets outside those immutable
  locations; and
- include `Vary: Accept-Encoding` on compressed asset responses.

Production provisioning must install the Nginx Brotli modules before the
configuration is activated. The deployment validation will fail closed if the
module is unavailable or the configuration is invalid.

## Deploy Sequence

1. Build Vite assets as today.
2. Publish Livewire assets from the installed package.
3. Install and validate the version-controlled Nginx configuration.
4. Reload Nginx only after validation succeeds.
5. Rebuild Laravel's cached configuration, routes, and views as today.

The published Livewire assets are generated during deployment and remain out
of source control, preventing stale package distributions from being committed.

## Verification

- Feature tests confirm both layouts preload the same versioned URL selected
  from the published Livewire manifest, and omit the preload without one.
- Build and full test suite pass.
- Production smoke checks use `curl --compressed -I` to confirm static
  Livewire and Vite assets return immutable cache headers and Brotli when the
  client supports it.
- A constrained-browser cold and repeat load confirms the runtime is not a PHP
  response and content-hashed Vite assets are not revalidated.
