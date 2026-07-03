<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies requests from Asana's app-components platform. There is no web
 * session or OAuth token here — the HMAC signature IS the security boundary,
 * so reject before touching anything else.
 *
 * Per Asana's contract: the x-asana-request-signature header carries an
 * HMAC-SHA256 (hex) of the "message", keyed with the app's client secret.
 * For GET requests the message is the raw query string (no leading "?");
 * for POST requests it is the raw value of the JSON body's "data" field.
 * Requests also carry an expires_at timestamp that must be in the future.
 */
class VerifyAsanaAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.asana.client_secret');
        $signature = (string) $request->header('x-asana-request-signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        $message = $this->messageToVerify($request);
        if ($message === null) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        $computed = hash_hmac('sha256', $message, $secret);
        if (! hash_equals($computed, $signature)) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        if ($this->isExpired($this->expiresAt($request))) {
            return response()->json(['error' => 'expired'], 401);
        }

        return $next($request);
    }

    private function messageToVerify(Request $request): ?string
    {
        if ($request->isMethod('GET')) {
            $query = $request->server->get('QUERY_STRING');

            return is_string($query) && $query !== '' ? $query : null;
        }

        $data = $this->rawDataBlob($request);

        return $data !== '' ? $data : null;
    }

    /**
     * The raw string value of the POST body's "data" field — the exact bytes
     * Asana signed, so it must not be re-encoded.
     */
    private function rawDataBlob(Request $request): string
    {
        $decoded = json_decode($request->getContent(), true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;

        return is_string($data) ? $data : '';
    }

    private function expiresAt(Request $request): ?string
    {
        $fromQuery = $request->query('expires_at');
        if (is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }

        $data = json_decode($this->rawDataBlob($request), true);
        $fromData = is_array($data) ? ($data['expires_at'] ?? null) : null;

        return is_string($fromData) && $fromData !== '' ? $fromData : null;
    }

    private function isExpired(?string $expiresAt): bool
    {
        if ($expiresAt === null) {
            return true;
        }

        $timestamp = strtotime($expiresAt);

        return $timestamp === false || $timestamp < now()->getTimestamp();
    }
}
