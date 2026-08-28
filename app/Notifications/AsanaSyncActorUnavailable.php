<?php

namespace App\Notifications;

use App\Notifications\Concerns\ThrottlesQueuedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AsanaSyncActorUnavailable extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesQueuedMail;

    public function __construct(
        public readonly ?string $actorName,
        public readonly ?string $actorEmail,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Action needed: Internal Tools Asana sync is paused')
            ->view('emails.integrations.asana-sync-actor-unavailable', [
                'actorName' => $this->actorName,
                'actorEmail' => $this->actorEmail,
                'reason' => $this->reason,
                'settingsUrl' => route('admin.integrations.asana'),
            ]);
    }
}
