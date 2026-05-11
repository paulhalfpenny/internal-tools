<?php

namespace App\Notifications;

use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyTimesheetOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly float $hours,
        public readonly float $target,
        public readonly CarbonImmutable $weekStart,
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
        $weekEnd = $this->weekStart->addDays(6);

        return (new MailMessage)
            ->subject('Your timesheet for last week is incomplete')
            ->view('emails.timesheets.weekly-overdue', [
                'userFirstName' => explode(' ', trim($notifiable->name))[0],
                'hours' => $this->hours,
                'target' => $this->target,
                'weekRange' => $this->weekStart->format('j M').' – '.$weekEnd->format('j M Y'),
                'timesheetUrl' => route('timesheet'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function toSlack(object $notifiable): array
    {
        $shortfall = max(0, $this->target - $this->hours);
        $weekEnd = $this->weekStart->addDays(6);

        $text = sprintf(
            ":rotating_light: *Last week's timesheet is incomplete.* You logged %.1f of %.1f hours for %s – %s (%.1fh missing). <%s|Back-fill the missing entries>.",
            $this->hours,
            $this->target,
            $this->weekStart->format('j M'),
            $weekEnd->format('j M'),
            $shortfall,
            route('timesheet'),
        );

        return ['text' => $text];
    }
}
