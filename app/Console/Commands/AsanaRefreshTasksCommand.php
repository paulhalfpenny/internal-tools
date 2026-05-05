<?php

namespace App\Console\Commands;

use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;

class AsanaRefreshTasksCommand extends Command
{
    protected $signature = 'asana:refresh-tasks';

    protected $description = 'Queue a tasks pull for every Internal Tools project linked to an Asana project.';

    public function handle(): int
    {
        $connectedUsers = User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->whereNotNull('asana_workspace_gid')
            ->where('is_active', true)
            ->get();

        if ($connectedUsers->isEmpty()) {
            $this->info('No connected Asana users; nothing to refresh.');

            return self::SUCCESS;
        }

        $linkedProjects = Project::query()
            ->whereNotNull('asana_project_gid')
            ->whereNotNull('asana_workspace_gid')
            ->where('is_archived', false)
            ->get();

        $dispatched = 0;
        foreach ($linkedProjects as $project) {
            /** @var User|null $actor */
            $actor = $connectedUsers->firstWhere('asana_workspace_gid', $project->asana_workspace_gid);
            if ($actor === null || $project->asana_project_gid === null) {
                continue;
            }
            PullAsanaTasksJob::dispatch($project->asana_project_gid, $actor->id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d task pull(s) across %d linked project(s).', $dispatched, $linkedProjects->count()));

        return self::SUCCESS;
    }
}
