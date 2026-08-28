<div>
    @if($syncActorUnavailable)
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @if($pendingActorRecoveryCount > 0)
                {{ $pendingActorRecoveryCount.' task '.\Illuminate\Support\Str::plural('total', $pendingActorRecoveryCount).' '.($pendingActorRecoveryCount === 1 ? 'is' : 'are').' pending Asana recovery.' }}
            @else
                Asana hours syncing is paused and hours are pending in Internal Tools.
            @endif
            @if($syncActor && ! $syncActor->asanaConnected())
                Reconnect {{ $syncActor->name }} to retry them automatically.
            @elseif(! $syncActor)
                Select a connected sync account below to retry them automatically.
            @else
                Pending totals will retry automatically through {{ $syncActor->name }}.
            @endif
        </div>
    @endif
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Asana integration</h1>
        <p class="text-sm text-gray-500 mt-1">Connection state and recent sync activity. Each user authorises Asana on their profile page.</p>
    </div>

    @if(session('asana_status'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded">{{ session('asana_status') }}</div>
    @endif
    @if(session('asana_error'))
        <div class="mb-4 px-4 py-2 bg-red-50 border border-red-200 text-red-700 text-sm rounded">{{ session('asana_error') }}</div>
    @endif

    <div class="grid grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Connected users</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1">{{ $connectedUserCount }}</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Cached Asana projects</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1">{{ $cachedProjectCount }}</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Linked Internal Tools projects</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1">{{ $linkedProjectCount }}</p>
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Queue health</h2>
        <div class="grid grid-cols-5 gap-6">
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Pending Asana jobs</p>
                <p class="text-2xl font-semibold {{ $pendingAsana > 50 ? 'text-yellow-600' : 'text-gray-900' }} mt-1">{{ $pendingAsana }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Failed jobs</p>
                <p class="text-2xl font-semibold {{ $failedAsana > 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $failedAsana }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Entries with sync error</p>
                <p class="text-2xl font-semibold {{ $entriesWithSyncError > 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $entriesWithSyncError }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Last hours sync</p>
                <p class="text-sm font-medium text-gray-900 mt-1">
                    {{ $lastSuccessfulSync ? \Carbon\Carbon::parse($lastSuccessfulSync)->diffForHumans() : '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Last task refresh</p>
                <p class="text-sm font-medium text-gray-900 mt-1">
                    {{ $lastSuccessfulTaskPull ? \Carbon\Carbon::parse($lastSuccessfulTaskPull)->diffForHumans() : '—' }}
                </p>
            </div>
        </div>
        @if($pendingAsana > 0 && ! $workerLikelyRunning)
            <div class="mt-4 px-4 py-2 bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm rounded">
                {{ $pendingAsana }} Asana job{{ $pendingAsana === 1 ? '' : 's' }} pending and no recent activity in the last 15 minutes. A queue worker may not be running.
            </div>
        @endif
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
        <div class="flex items-start justify-between mb-4">
            <h2 class="text-sm font-semibold text-gray-700">Connected users</h2>
            <button wire:click="pullProjects" class="px-3 py-1.5 text-xs font-medium text-blue-700 border border-blue-200 rounded hover:bg-blue-50">
                Pull projects from my workspace
            </button>
        </div>
        @if($connectedUsers->isEmpty())
            <p class="text-sm text-gray-500">No users have connected Asana yet.</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($connectedUsers as $u)
                    <li class="py-2 flex items-center justify-between text-sm">
                        <span class="text-gray-700">
                            {{ $u->name }} <span class="text-gray-400">({{ $u->email }})</span>
                            @if(! $u->asanaConnected())
                                <span class="text-red-600">— disconnected</span>
                            @endif
                        </span>
                        <span class="text-xs text-gray-500">workspace {{ $u->asana_workspace_gid ?? '—' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mt-4 pt-4 border-t border-gray-100">
            <label for="syncActor" class="block text-xs font-medium text-gray-700 mb-1">
                Asana sync account
            </label>
            <p class="text-xs text-gray-500 mb-2">
                Hours-tracked updates in Asana are attributed only to this account.
                Selecting “None” pauses hours synchronization.
            </p>
            <select id="syncActor" wire:model.live="syncActorUserId"
                    class="w-full max-w-sm text-sm border-gray-300 rounded">
                <option value="">None (hours sync paused)</option>
                @foreach($connectedUsers as $u)
                    <option value="{{ $u->id }}">
                        {{ $u->name }} ({{ $u->email }}){{ $u->asanaConnected() ? '' : ' — disconnected' }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Recent sync log</h2>
        @if($recentLogs->isEmpty())
            <p class="text-sm text-gray-500">Nothing has happened yet.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500 text-left">
                        <th class="py-1.5 pr-2 font-medium">When</th>
                        <th class="py-1.5 pr-2 font-medium">Level</th>
                        <th class="py-1.5 pr-2 font-medium">Event</th>
                        <th class="py-1.5 pr-2 font-medium">Context</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentLogs as $log)
                        <tr class="border-t border-gray-100">
                            <td class="py-2 pr-2 text-xs text-gray-500 whitespace-nowrap align-middle">{{ $log->created_at->diffForHumans() }}</td>
                            <td class="py-2 pr-2 align-middle">
                                <span class="inline-flex items-center text-xs leading-4 px-2 py-0.5 rounded
                                    @if($log->level === 'error') bg-red-50 text-red-700
                                    @elseif($log->level === 'warn') bg-yellow-50 text-yellow-700
                                    @else bg-gray-50 text-gray-700 @endif">
                                    {{ $log->level }}
                                </span>
                            </td>
                            <td class="py-2 pr-2 text-xs text-gray-700 font-mono align-middle">{{ $log->event }}</td>
                            <td class="py-2 pr-2 text-xs text-gray-500 break-all align-middle">{{ json_encode($log->context, JSON_UNESCAPED_SLASHES) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
