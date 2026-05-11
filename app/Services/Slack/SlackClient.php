<?php

namespace App\Services\Slack;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlackClient
{
    public const BASE_URL = 'https://slack.com/api';

    public function __construct(private readonly ?string $token = null) {}

    public function isConfigured(): bool
    {
        return $this->token() !== null && $this->token() !== '';
    }

    /**
     * Resolve a Slack user ID for the given email and persist it on the User.
     *
     * Returns null (and logs a warning) if Slack is not configured or the lookup fails.
     */
    public function resolveUserId(User $user): ?string
    {
        if ($user->slack_user_id) {
            return $user->slack_user_id;
        }

        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->callWithRateLimit(
            fn () => $this->client()->get(self::BASE_URL.'/users.lookupByEmail', [
                'email' => $user->email,
            ]),
            'users.lookupByEmail',
            ['user_id' => $user->id, 'email' => $user->email],
        );

        if ($response === null) {
            return null;
        }

        $body = $response->json();

        if (! ($body['ok'] ?? false)) {
            Log::warning('Slack users.lookupByEmail failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $body['error'] ?? 'unknown',
            ]);

            return null;
        }

        $slackId = $body['user']['id'] ?? null;
        if ($slackId !== null) {
            $user->forceFill(['slack_user_id' => $slackId])->save();
        }

        return $slackId;
    }

    /**
     * Send a Slack DM to the given user. Returns true on success.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function sendDirectMessage(User $user, string $text, array $blocks = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $slackId = $this->resolveUserId($user);
        if ($slackId === null) {
            return false;
        }

        $payload = ['channel' => $slackId, 'text' => $text];
        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        $response = $this->callWithRateLimit(
            fn () => $this->client()->post(self::BASE_URL.'/chat.postMessage', $payload),
            'chat.postMessage',
            ['user_id' => $user->id, 'slack_user_id' => $slackId],
        );

        if ($response === null) {
            return false;
        }

        $body = $response->json();
        if (! ($body['ok'] ?? false)) {
            Log::warning('Slack chat.postMessage failed', [
                'user_id' => $user->id,
                'slack_user_id' => $slackId,
                'error' => $body['error'] ?? 'unknown',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Call a Slack endpoint with bounded 429 retry-after handling and connection-error
     * trapping. Returns null when the request ultimately fails or hits the retry cap.
     *
     * @param  callable(): Response  $request
     * @param  array<string, mixed>  $logContext
     */
    private function callWithRateLimit(callable $request, string $endpoint, array $logContext): ?Response
    {
        $attempts = 0;

        while ($attempts < 3) {
            try {
                $response = $request();
            } catch (ConnectionException|Throwable $e) {
                Log::warning('Slack '.$endpoint.' connection failed', $logContext + ['error' => $e->getMessage()]);

                return null;
            }

            if ($response->status() !== 429) {
                return $response;
            }

            $retryAfter = (int) ($response->header('Retry-After') ?: 1);
            // Cap the wait so a misbehaving server can't pin a long-running command.
            $retryAfter = min($retryAfter, 30);
            Log::info('Slack '.$endpoint.' rate-limited; retrying', $logContext + ['retry_after' => $retryAfter]);
            sleep($retryAfter);
            $attempts++;
        }

        Log::warning('Slack '.$endpoint.' gave up after rate-limit retries', $logContext);

        return null;
    }

    private function token(): ?string
    {
        return $this->token ?? config('services.slack.notifications.bot_user_oauth_token');
    }

    private function client(): PendingRequest
    {
        // Callers only reach client() after isConfigured() returned true, so the
        // token is guaranteed non-null here.
        return Http::withToken($this->token() ?? '')
            ->acceptJson()
            ->asJson();
    }
}
