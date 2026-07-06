<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

class BackfillTaskBillability extends Command
{
    protected $signature = 'tasks:backfill-billability';

    protected $description = 'Re-apply each task\'s Agency/JDW billable default to its existing project links, correcting stale project-task pivots.';

    public function handle(): int
    {
        $tasks = Task::with('projects.client')->get();
        $projectLinks = 0;

        foreach ($tasks as $task) {
            $task->reapplyBillabilityToProjects();
            $projectLinks += $task->projects->count();
        }

        $this->info("Reapplied billability defaults across {$projectLinks} project-task links for {$tasks->count()} tasks.");

        return self::SUCCESS;
    }
}
