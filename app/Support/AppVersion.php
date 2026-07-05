<?php

namespace App\Support;

/**
 * Identifies the currently-deployed front-end build. Derived from the Vite
 * manifest so it changes exactly when a deploy ships new assets — which is the
 * signal the stale-tab guard uses to prompt open tabs to reload.
 *
 * Intentionally NOT memoised across requests: a long-lived PHP-FPM worker that
 * survives a deploy must report the new manifest immediately, or the guard
 * would never fire. Reading the small manifest per request is negligible.
 */
final class AppVersion
{
    public static function current(): string
    {
        $manifest = public_path('build/manifest.json');

        if (is_file($manifest)) {
            $hash = md5_file($manifest);

            if ($hash !== false) {
                return substr($hash, 0, 12);
            }
        }

        // No built manifest (e.g. local dev with the Vite dev server): a stable
        // placeholder so the guard simply never fires rather than false-firing.
        return 'dev';
    }
}
