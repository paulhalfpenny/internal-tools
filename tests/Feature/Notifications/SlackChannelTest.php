<?php

use App\Models\User;
use App\Notifications\Channels\SlackChannel;
use App\Services\Slack\SlackClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

class SimpleSlackNotification extends Notification
{
    public function __construct(public readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): string
    {
        return $this->message;
    }
}

beforeEach(function () {
    config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test');
});

test('sends DM via Slack chat.postMessage when configured', function () {
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U123ABC']]),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->create(['email' => 'sam@filteragency.com']);

    $channel = app(SlackChannel::class);
    $sent = $channel->send($user, new SimpleSlackNotification('hi sam'));

    expect($sent)->toBeTrue();
    expect($user->refresh()->slack_user_id)->toBe('U123ABC');

    Http::assertSent(fn ($req) => $req->url() === SlackClient::BASE_URL.'/chat.postMessage'
        && $req['channel'] === 'U123ABC'
        && $req['text'] === 'hi sam');
});

test('reuses cached slack_user_id and skips lookup', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->create(['slack_user_id' => 'UCACHED']);

    expect(app(SlackChannel::class)->send($user, new SimpleSlackNotification('hi')))->toBeTrue();

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'users.lookupByEmail'));
});

test('skips when slack_notifications_enabled is false', function () {
    Http::fake();

    $user = User::factory()->create(['slack_notifications_enabled' => false]);

    expect(app(SlackChannel::class)->send($user, new SimpleSlackNotification('hi')))->toBeFalse();

    Http::assertNothingSent();
});

test('skips inactive users', function () {
    Http::fake();

    $user = User::factory()->create(['is_active' => false]);

    expect(app(SlackChannel::class)->send($user, new SimpleSlackNotification('hi')))->toBeFalse();

    Http::assertNothingSent();
});

test('returns false when Slack is not configured', function () {
    config()->set('services.slack.notifications.bot_user_oauth_token', null);
    Http::fake();

    $user = User::factory()->create();

    expect(app(SlackChannel::class)->send($user, new SimpleSlackNotification('hi')))->toBeFalse();

    Http::assertNothingSent();
});

test('blocks payload is sent as JSON, not form-encoded', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->create(['slack_user_id' => 'UJSON']);

    $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'hi']]];
    $client = app(SlackClient::class);
    $client->sendDirectMessage($user, 'hi', $blocks);

    Http::assertSent(function ($req) use ($blocks) {
        return $req->url() === SlackClient::BASE_URL.'/chat.postMessage'
            && $req['blocks'] === $blocks
            && $req->hasHeader('Content-Type', 'application/json');
    });
});

test('returns false when lookupByEmail fails', function () {
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
    ]);

    $user = User::factory()->create();

    expect(app(SlackChannel::class)->send($user, new SimpleSlackNotification('hi')))->toBeFalse();
    expect($user->refresh()->slack_user_id)->toBeNull();
});
