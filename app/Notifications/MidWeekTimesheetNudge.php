<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\ThrottlesQueuedMail;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MidWeekTimesheetNudge extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesQueuedMail;

    public function __construct(
        public readonly float $hours,
        public readonly float $target,
        public readonly float $threshold,
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
            ->subject("You're behind on this week's timesheet")
            ->view('emails.timesheets.mid-week-nudge', [
                'userFirstName' => explode(' ', trim($notifiable->name))[0],
                'hours' => $this->hours,
                'target' => $this->target,
                'threshold' => $this->threshold,
                'weekRange' => $this->weekStart->format('j M').' – '.$weekEnd->format('j M Y'),
                'timesheetUrl' => route('timesheet'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function toSlack(object $notifiable): array
    {
        /** @var User $notifiable */
        $checkpointShortfall = max(0, $this->threshold - $this->hours);
        $remainingHours = max(0, $this->target - $this->hours);
        $url = route('timesheet');

        $text = sprintf(
            ":warning: *Timesheet check-in.* By the end of Wednesday, you've logged %.1fh. Your mid-week checkpoint is %.1fh, so you're %.1fh short. Based on that Wednesday snapshot, %.1fh remain to log for the week. <%s|Open your timesheet> when you have a moment.",
            $this->hours,
            $this->threshold,
            $checkpointShortfall,
            $remainingHours,
            $url,
        );

        return ['text' => $text];
    }
}
