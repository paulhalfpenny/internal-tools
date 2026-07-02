<?php

namespace App\Console\Commands;

use App\Enums\Role;
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
            ->orderByRaw('case when role = ? then 0 else 1 end', [Role::Admin->value])
            ->orderBy('id')
            ->get(['id', 'role', 'asana_workspace_gid']);

        if ($connectedUsers->isEmpty()) {
            $this->info('No connected Asana users; nothing to refresh.');

            return self::SUCCESS;
        }

        $workspaceGids = $connectedUsers->pluck('asana_workspace_gid')->unique()->filter();

        foreach ($workspaceGids as $workspaceGid) {
            $actors = $connectedUsers
                ->where('asana_workspace_gid', $workspaceGid)
                ->values();

            /** @var User $actor */
            $actor = $actors->first();
            $fallbackUserIds = $actors->skip(1)->pluck('id')->values()->all();

            PullAsanaProjectsJob::dispatch($workspaceGid, $actor->id, $fallbackUserIds);
        }

        $this->info(sprintf('Dispatched %d workspace project pull(s).', $workspaceGids->count()));

        return self::SUCCESS;
    }
}
