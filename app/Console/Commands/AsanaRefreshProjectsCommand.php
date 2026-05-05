<?php

namespace App\Console\Commands;

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Models\User;
use Illuminate\Console\Command;

class AsanaRefreshProjectsCommand extends Command
{
    protected $signature = 'asana:refresh-projects';

    protected $description = 'Queue a workspace-projects pull for every workspace that has a connected user.';

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

        $workspaceGids = $connectedUsers->pluck('asana_workspace_gid')->unique()->filter();

        foreach ($workspaceGids as $workspaceGid) {
            /** @var User $actor */
            $actor = $connectedUsers->firstWhere('asana_workspace_gid', $workspaceGid);
            PullAsanaProjectsJob::dispatch($workspaceGid, $actor->id);
        }

        $this->info(sprintf('Dispatched %d workspace project pull(s).', $workspaceGids->count()));

        return self::SUCCESS;
    }
}
