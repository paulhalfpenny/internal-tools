<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Slack\SlackClient;
use Illuminate\Notifications\Notification;

class SlackChannel
{
    public function __construct(private readonly SlackClient $slack) {}

    public function send(object $notifiable, Notification $notification): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        if (! $notifiable->slack_notifications_enabled || ! $notifiable->is_active) {
            return false;
        }

        if (! method_exists($notification, 'toSlack')) {
            return false;
        }

        $payload = $notification->toSlack($notifiable);

        if (is_string($payload)) {
            return $this->slack->sendDirectMessage($notifiable, $payload);
        }

        if (is_array($payload)) {
            $text = $payload['text'] ?? '';
            $blocks = $payload['blocks'] ?? [];

            return $this->slack->sendDirectMessage($notifiable, $text, $blocks);
        }

        return false;
    }
}
