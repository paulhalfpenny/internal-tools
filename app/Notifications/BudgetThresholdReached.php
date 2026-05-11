<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    /**
     * @return array<int, string>
     */
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
        $project = $this->project;
        $client = $project->client->name;
        $verb = $this->threshold >= 100 ? 'over budget' : 'at '.$this->threshold.'%';

        $subject = trim(sprintf(
            '[Budget alert] %s — %s %s',
            $client.' / '.$project->name,
            $verb,
            $this->periodKey === 'lifetime' ? '' : '('.$this->periodKey.')'
        ));

        $periodLabel = 'lifetime';
        if ($this->periodKey !== 'lifetime') {
            $parsed = CarbonImmutable::createFromFormat('Y-m', $this->periodKey);
            $periodLabel = $parsed !== null ? $parsed->format('F Y') : $this->periodKey;
        }

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.budgets.threshold-reached', [
                'projectName' => $project->name,
                'client' => $client,
                'threshold' => $this->threshold,
                'periodKey' => $this->periodKey,
                'periodLabel' => $periodLabel,
                'percentUsed' => $this->percentUsed,
                'budgetAmount' => $this->budgetAmount,
                'actualAmount' => $this->actualAmount,
                'projectBudgetUrl' => route('reports.projects.budget', $project),
            ]);
    }
}
