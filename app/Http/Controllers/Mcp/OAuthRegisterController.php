<?php

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController as LaravelMcpOAuthRegisterController;

class OAuthRegisterController
{
    public function __invoke(Request $request, LaravelMcpOAuthRegisterController $register): JsonResponse
    {
        $maxAttempts = max(1, (int) config('mcp.oauth_registration_limit_per_minute', 10));
        $key = 'mcp-oauth-register:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'slow_down',
                'error_description' => 'Too many OAuth client registration attempts.',
            ], 429)->withHeaders([
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $register($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $maxAttempts));

        return $response;
    }
}
