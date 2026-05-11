<?php

namespace App\Console\Commands;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Notifications\BudgetThresholdReached;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CheckBudgetAlerts extends Command
{
    protected $signature = 'app:check-budget-alerts {--dry-run : Print what would be sent without sending}';

    protected $description = 'Check budgeted projects and send threshold-crossed alerts (80%, 100%) to admins and project managers.';

    private const THRESHOLDS = [80, 100];

    public function handle(ProjectBudgetCalculator $calculator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = CarbonImmutable::now();

        $projects = Project::with(['client', 'manager'])
            ->whereNotNull('budget_type')
            ->where('is_archived', false)
            ->get();

        if ($projects->isEmpty()) {
            $this->info('No budgeted active projects to check.');

            return self::SUCCESS;
        }

        $admins = User::where('role', Role::Admin)->where('is_active', true)->get();
        $totalSent = 0;
        $statuses = $calculator->forProjects($projects, $now);

        foreach ($projects as $project) {
            $status = $statuses[$project->id] ?? null;
            if ($status === null || $status->budgetAmount <= 0) {
                continue;
            }

            $percent = $status->percentUsed();
            $periodKey = $project->budget_type === BudgetType::MonthlyCi ? $now->format('Y-m') : 'lifetime';

            foreach (self::THRESHOLDS as $threshold) {
                if ($percent < $threshold) {
                    continue;
                }

                $recipients = $this->recipients($admins, $project);
                if ($recipients->isEmpty()) {
                    continue;
                }

                $notification = new BudgetThresholdReached(
                    project: $project,
                    threshold: $threshold,
                    periodKey: $periodKey,
                    percentUsed: $percent,
                    budgetAmount: $status->budgetAmount,
                    actualAmount: $status->actualAmount,
                );

                if ($dryRun) {
                    $alreadyAlerted = DB::table('project_budget_alerts')
                        ->where('project_id', $project->id)
                        ->where('threshold', $threshold)
                        ->where('period_key', $periodKey)
                        ->exists();

                    if ($alreadyAlerted) {
                        continue;
                    }

                    $this->line(sprintf(
                        '[dry-run] %s — %d%% threshold (%.1f%% used) → %d recipients (period %s)',
                        $project->name,
                        $threshold,
                        $percent,
                        $recipients->count(),
                        $periodKey,
                    ));

                    continue;
                }

                // Reserve the dedupe row first; the unique key (project_id, threshold, period_key)
                // means insertOrIgnore returns 0 if another worker already claimed it. Only send
                // notifications when we successfully claimed it.
                $claimed = DB::table('project_budget_alerts')->insertOrIgnore([
                    'project_id' => $project->id,
                    'threshold' => $threshold,
                    'period_key' => $periodKey,
                    'alerted_at' => $now->toDateTimeString(),
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);

                if ($claimed === 0) {
                    continue;
                }

                Notification::send($recipients, $notification);
                $totalSent++;
            }
        }

        $this->info($dryRun ? 'Dry run complete.' : "Sent {$totalSent} budget threshold alert(s).");

        return self::SUCCESS;
    }

    /**
     * Admins (always) plus the project's manager (if set and not already an admin).
     *
     * @param  Collection<int, User>  $admins
     * @return Collection<int, User>
     */
    private function recipients($admins, Project $project)
    {
        $recipients = $admins->keyBy('id');

        if ($project->manager !== null && $project->manager->is_active && ! $recipients->has($project->manager->id)) {
            $recipients->put($project->manager->id, $project->manager);
        }

        return $recipients->values();
    }
}
