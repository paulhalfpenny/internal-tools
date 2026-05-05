<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Asana connection</h1>
        <p class="text-sm text-gray-500 mt-1">Link your Asana account so the time you log on linked projects shows up in Asana.</p>
    </div>

    @if(session('asana_status'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded">{{ session('asana_status') }}</div>
    @endif
    @if(session('asana_error'))
        <div class="mb-4 px-4 py-2 bg-red-50 border border-red-200 text-red-700 text-sm rounded">{{ session('asana_error') }}</div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-xl">
        @if($connected)
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-gray-700">
                        Connected as
                        <span class="font-medium">{{ auth()->user()->name }}</span>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">Asana user gid: {{ auth()->user()->asana_user_gid }}</p>
                </div>
                <form method="POST" action="{{ route('integrations.asana.disconnect') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-red-700 border border-red-200 rounded hover:bg-red-50">
                        Disconnect
                    </button>
                </form>
            </div>

            <div class="mt-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Active workspace</label>
                <select
                    class="w-full border border-gray-300 rounded text-sm px-3 py-2"
                    style="-webkit-appearance:none;-moz-appearance:none;appearance:none;"
                    wire:change="setWorkspace($event.target.value)"
                >
                    <option value="">Select a workspace…</option>
                    @foreach($workspaces as $workspace)
                        <option value="{{ $workspace->gid }}" @selected($selectedWorkspace === $workspace->gid)>{{ $workspace->name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-2">Projects and tasks for this workspace power the picker on linked projects.</p>
            </div>
        @else
            <p class="text-sm text-gray-700">
                You haven't connected Asana yet. Click below to authorise Internal Tools to read your Asana projects/tasks
                and update a custom field on each task with the cumulative hours tracked here.
            </p>
            <a href="{{ route('integrations.asana.redirect') }}"
               class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">
                Connect Asana
            </a>
        @endif
    </div>
</div>
