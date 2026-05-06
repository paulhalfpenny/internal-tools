<?php

namespace App\Services\Slack;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $response = $this->client()->get(self::BASE_URL.'/users.lookupByEmail', [
            'email' => $user->email,
        ]);

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

        $response = $this->client()->post(self::BASE_URL.'/chat.postMessage', $payload);

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

    private function token(): ?string
    {
        return $this->token ?? config('services.slack.notifications.bot_user_oauth_token');
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->asJson();
    }
}
