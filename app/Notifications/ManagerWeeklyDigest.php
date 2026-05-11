<?php

namespace App\Notifications;

use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManagerWeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{name: string, email: string, hours: float, target: float}>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly CarbonImmutable $weekStart,
        public readonly bool $isAdminDigest = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! NotificationSettings::emailEnabled()) {
            return [];
        }

        if ($notifiable instanceof User && ! $notifiable->email_notifications_enabled) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $weekEnd = $this->weekStart->addDays(6);

        return (new MailMessage)
            ->subject(($this->isAdminDigest ? 'Team' : 'Direct reports').' timesheet status (so far this week) — '.$this->weekStart->format('j M').' – '.$weekEnd->format('j M Y'))
            ->view('emails.timesheets.manager-digest', [
                'managerFirstName' => explode(' ', trim($notifiable->name))[0],
                'rows' => $this->rows,
                'weekRange' => $this->weekStart->format('j M').' – '.$weekEnd->format('j M Y'),
                'isAdminDigest' => $this->isAdminDigest,
                'adminUrl' => route('admin.timesheets'),
            ]);
    }
}
