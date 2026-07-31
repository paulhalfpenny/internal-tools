<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\ThrottlesQueuedMail;
use App\Settings\NotificationSettings;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AsanaConnectionLost extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesQueuedMail;

    public function __construct(
        public readonly ?CarbonInterface $lostAt = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        if ($notifiable instanceof User && $notifiable->email_notifications_enabled && NotificationSettings::emailEnabled()) {
            $channels[] = 'mail';
        }
        if ($notifiable instanceof User && $notifiable->slack_notifications_enabled && NotificationSettings::slackEnabled()) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        return (new MailMessage)
            ->subject('Action needed: your Asana connection has dropped')
            ->view('emails.integrations.asana-connection-lost', [
                'userFirstName' => explode(' ', trim($notifiable->name))[0],
                'lostAtLabel' => $this->lostAt?->format('j M Y, H:i'),
                'reconnectUrl' => route('profile.asana'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function toSlack(object $notifiable): array
    {
        $text = sprintf(
            ':link: *Your Asana connection has dropped.* Task syncing and hours push-back are paused until you reconnect%s. <%s|Reconnect Asana>.',
            $this->lostAt !== null ? ' (stopped working '.$this->lostAt->diffForHumans().')' : '',
            route('profile.asana'),
        );

        return ['text' => $text];
    }
}
