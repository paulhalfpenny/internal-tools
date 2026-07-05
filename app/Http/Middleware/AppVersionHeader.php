<?php

namespace App\Http\Middleware;

use App\Support\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every web response (including Livewire XHR responses) with the current
 * front-end build version so a stale open tab can detect that a newer version
 * has been deployed and offer to reload.
 */
class AppVersionHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-App-Version', AppVersion::current());

        return $response;
    }
}
