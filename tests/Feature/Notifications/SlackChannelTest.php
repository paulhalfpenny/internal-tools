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
