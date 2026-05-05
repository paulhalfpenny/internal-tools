<?php

namespace App\Livewire\Admin\Integrations;

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AsanaSettings extends Component
{
    public function mount(): void
    {
        Gate::authorize('access-admin');
    }

    public function pullProjects(): void
    {
        Gate::authorize('access-admin');

        /** @var User $user */
        $user = auth()->user();
        if (! $user->asanaConnected() || $user->asana_workspace_gid === null) {
            session()->flash('asana_error', 'Connect your Asana account on the profile page first.');

            return;
        }

        PullAsanaProjectsJob::dispatch($user->asana_workspace_gid, $user->id);
        session()->flash('asana_status', 'Pulling projects in the background.');
    }

    public function render(): View
    {
        // Match by job class slug. Backslash-escaping PHP -> MySQL LIKE gets messy fast,
        // and "Asana" only appears in our integration jobs in this codebase.
        $pendingAsana = DB::table('jobs')->where('payload', 'like', '%Asana%')->count();
        $failedAsana = DB::table('failed_jobs')->where('payload', 'like', '%Asana%')->count();
        $entriesWithSyncError = TimeEntry::query()->whereNotNull('asana_sync_error')->count();

        $lastSuccessfulSync = AsanaSyncLog::query()
            ->where('event', 'asana.sync_hours.pushed')
            ->orderByDesc('id')
            ->value('created_at');

        $recentSuccess = AsanaSyncLog::query()
            ->where('level', 'info')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();

        $workerLikelyRunning = $pendingAsana === 0 || $recentSuccess;

        return view('livewire.admin.integrations.asana', [
            'connectedUserCount' => User::query()->whereNotNull('asana_access_token')->count(),
            'cachedProjectCount' => AsanaProject::query()->count(),
            'linkedProjectCount' => Project::query()->whereNotNull('asana_project_gid')->count(),
            'pendingAsana' => $pendingAsana,
            'failedAsana' => $failedAsana,
            'entriesWithSyncError' => $entriesWithSyncError,
            'lastSuccessfulSync' => $lastSuccessfulSync,
            'workerLikelyRunning' => $workerLikelyRunning,
            'recentLogs' => AsanaSyncLog::query()->orderByDesc('id')->limit(20)->get(),
            'connectedUsers' => User::query()
                ->whereNotNull('asana_access_token')
                ->whereNotNull('asana_user_gid')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
