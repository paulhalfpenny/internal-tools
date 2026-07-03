<div class="p-5" x-data="{ embedded: window.self !== window.top }">
    @if($status === 'missing')
        <div class="text-center py-10">
            <p class="text-sm font-medium text-gray-900">This Asana task isn't synced to Internal Tools yet.</p>
            <p class="mt-2 text-sm text-gray-500">Refresh Asana tasks from the timesheet, then try again.</p>
            <a href="{{ route('timesheet') }}" target="_blank" rel="noopener"
               class="mt-4 inline-block text-sm text-blue-700 hover:underline">Open Internal Tools ↗</a>
        </div>
    @elseif($status === 'unmapped')
        <div class="text-center py-10">
            <p class="text-sm font-medium text-gray-900">This Asana board isn't linked to any of your projects.</p>
            <p class="mt-2 text-sm text-gray-500">Ask an admin to link the board to an Internal Tools project, or log the time from your timesheet.</p>
            <a href="{{ route('timesheet') }}" target="_blank" rel="noopener"
               class="mt-4 inline-block text-sm text-blue-700 hover:underline">Open Internal Tools ↗</a>
        </div>
    @else
        <div class="flex items-center justify-between gap-2 pb-4 border-b border-gray-100">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-gray-400">Log time for</p>
                <p class="text-sm font-semibold text-gray-900 truncate" title="{{ $taskName }}">{{ $taskName }}</p>
            </div>
            <a href="{{ route('timesheet') }}" target="_blank" rel="noopener"
               class="flex-none text-xs text-gray-500 hover:text-gray-700 hover:underline">My timesheet ↗</a>
        </div>

        @if($savedSummary !== '')
            <div class="mt-4 px-3 py-2 rounded-lg text-sm {{ $timerStarted ? 'bg-blue-50 text-blue-900' : 'bg-green-50 text-green-900' }}">
                ✓ {{ $savedSummary }}
            </div>
        @endif

        <form wire:submit="save" class="mt-4 space-y-4">
            <div>
                <label for="embed-project" class="block text-xs font-medium text-gray-600 mb-1">Project</label>
                <select id="embed-project" wire:model.live="selectedProjectId"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}{{ $project->client ? ' — '.$project->client->name : '' }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="embed-task" class="block text-xs font-medium text-gray-600 mb-1">Task</label>
                <select id="embed-task" wire:model="selectedTaskId"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Select a task…</option>
                    @foreach(($projects->firstWhere('id', $selectedProjectId)?->tasks ?? []) as $task)
                        <option value="{{ $task->id }}">{{ $task->name }}</option>
                    @endforeach
                </select>
                @error('selectedTaskId')
                    <p class="mt-1 text-xs text-red-600">Pick a task.</p>
                @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="embed-date" class="block text-xs font-medium text-gray-600 mb-1">Date</label>
                    <input id="embed-date" type="date" wire:model="entryDate"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="embed-hours" class="block text-xs font-medium text-gray-600 mb-1">Hours</label>
                    <input id="embed-hours" type="text" wire:model="hoursInput" placeholder="1:30 or 1.5"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('hoursInput')
                        <p class="mt-1 text-xs text-red-600">Enter the hours to log.</p>
                    @enderror
                    @if($hoursError !== '')
                        <p class="mt-1 text-xs text-red-600">{{ $hoursError }}</p>
                    @endif
                </div>
            </div>

            <div>
                <label for="embed-notes" class="block text-xs font-medium text-gray-600 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                <textarea id="embed-notes" wire:model="notes" rows="2"
                          class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white rounded-full transition"
                        style="background-color: #002f5f;">
                    Log time
                </button>
                <button type="button" wire:click="startTimer"
                        class="px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-full hover:bg-gray-50 transition">
                    Start timer
                </button>
                {{-- Only meaningful inside the extension dialog. --}}
                <button type="button" x-show="embedded" x-cloak
                        x-on:click="window.parent.postMessage({ type: 'filter-log-time:close' }, 'https://app.asana.com')"
                        class="ml-auto px-4 py-2 text-sm text-gray-600 hover:text-gray-800 transition">
                    Cancel
                </button>
            </div>
        </form>
    @endif
</div>

@script
<script>
    // Inside the extension's <dialog> iframe on app.asana.com. The
    // extension listens for these (see asana-extension/content.js):
    // saved → dismiss after a beat; height → size the dialog to the form.
    const embedded = window.self !== window.top;
    const post = (message) => window.parent.postMessage(message, 'https://app.asana.com');

    $wire.on('asana-entry-saved', () => {
        if (embedded) post({ type: 'filter-log-time:saved' });
    });

    if (embedded) {
        const reportHeight = () => post({
            type: 'filter-log-time:height',
            height: document.documentElement.scrollHeight,
        });
        new ResizeObserver(reportHeight).observe(document.body);
        reportHeight();
    }
</script>
@endscript
