<?php

namespace App\Notifications;

use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyTimesheetOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly float $hours,
        public readonly float $target,
        public readonly CarbonImmutable $monthStart,
    ) {}

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
            ->subject('Your timesheet for '.$this->monthStart->format('F Y').' is incomplete')
            ->view('emails.timesheets.monthly-overdue', [
                'userFirstName' => explode(' ', trim($notifiable->name))[0],
                'hours' => $this->hours,
                'target' => $this->target,
                'monthLabel' => $this->monthStart->format('F Y'),
                'timesheetUrl' => route('timesheet'),
            ]);
    }

    public function toSlack(object $notifiable): array
    {
        $shortfall = max(0, $this->target - $this->hours);

        $text = sprintf(
            ':calendar: *Monthly timesheet incomplete.* You logged %.1f of %.1f hours for %s (%.1fh missing). <%s|Open your timesheet>.',
            $this->hours,
            $this->target,
            $this->monthStart->format('F Y'),
            $shortfall,
            route('timesheet'),
        );

        return ['text' => $text];
    }
}
