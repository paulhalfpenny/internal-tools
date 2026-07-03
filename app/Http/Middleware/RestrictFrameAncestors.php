<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only Asana (and the app itself) may embed pages in an iframe. Framing is
 * needed for the browser extension's log-time overlay on app.asana.com;
 * everything else is denied to keep clickjacking off the table — especially
 * since the session cookie is SameSite=None (config/session.php).
 */
class RestrictFrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self' https://app.asana.com"
        );

        return $response;
    }
}
