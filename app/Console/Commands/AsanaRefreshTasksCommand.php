<?php

namespace App\Console\Commands;

use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $links = DB::table('project_asana_links')
            ->join('projects', 'project_asana_links.project_id', '=', 'projects.id')
            ->join('asana_projects', 'project_asana_links.asana_project_gid', '=', 'asana_projects.gid')
            ->where('projects.is_archived', false)
            ->select(
                'project_asana_links.asana_project_gid as board_gid',
                'asana_projects.workspace_gid as workspace_gid',
            )
            ->get();

        $dispatched = 0;
        foreach ($links as $link) {
            /** @var User|null $actor */
            $actor = $connectedUsers->firstWhere('asana_workspace_gid', $link->workspace_gid);
            if ($actor === null) {
                continue;
            }
            PullAsanaTasksJob::dispatch($link->board_gid, $actor->id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d task pull(s) across %d linked board(s).', $dispatched, $links->count()));

        return self::SUCCESS;
    }
}
