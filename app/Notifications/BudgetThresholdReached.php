<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetThresholdReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly int $threshold,
        public readonly string $periodKey,
        public readonly float $percentUsed,
        public readonly float $budgetAmount,
        public readonly float $actualAmount,
    ) {}

    public function via(object $notifiable): array
    {
        // Email is the primary channel per user decision; Slack remains opt-in via existing settings.
        $channels = [];
        if ($notifiable instanceof User && $notifiable->email_notifications_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->threshold >= 100 ? 'over budget' : 'at '.$this->threshold.'%';
        $project = $this->project;
        $client = $project->client?->name;

        $subject = sprintf(
            '[Budget alert] %s — %s %s',
            $client ? $client.' / '.$project->name : $project->name,
            $verb,
            $this->periodKey === 'lifetime' ? '' : '('.$this->periodKey.')'
        );

        return (new MailMessage)
            ->subject(trim($subject))
            ->greeting('Budget threshold reached')
            ->line(sprintf(
                '%s "%s" has reached %.1f%% of budget (%.0f%% threshold crossed).',
                $client ? $client.' /' : '',
                $project->name,
                $this->percentUsed,
                $this->threshold,
            ))
            ->line(sprintf('Budget: £%s — Spent: £%s', number_format($this->budgetAmount, 2), number_format($this->actualAmount, 2)))
            ->action('Open project budget', route('reports.projects.budget', $project));
    }
}
