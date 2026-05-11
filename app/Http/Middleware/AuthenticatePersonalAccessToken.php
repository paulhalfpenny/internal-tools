<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePersonalAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return response()->json(['error' => 'missing_token'], 401);
        }

        $token = PersonalAccessToken::findActiveByPlaintext($bearer);
        if ($token === null) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $user = $token->user;
        if (! $user->is_active) {
            return response()->json(['error' => 'user_inactive'], 401);
        }

        $token->touchLastUsed();
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
