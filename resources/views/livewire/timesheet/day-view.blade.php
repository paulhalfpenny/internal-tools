<div
    class="max-w-4xl mx-auto px-4 py-6"
    x-data="{}"
    @keydown.n.window="$wire.openNewModal()"
>
    {{-- Week strip --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="grid grid-cols-7 divide-x divide-gray-100">
            @foreach ($weekDays as $day)
                @php
                    $dateStr = $day->toDateString();
                    $isToday = $day->isToday();
                    $isSelected = $dateStr === $selectedDate;
                    $total = $dayTotals[$dateStr] ?? 0;
                @endphp
                <button
                    wire:click="selectDate('{{ $dateStr }}')"
                    class="flex flex-col items-center py-3 px-2 hover:bg-gray-50 transition {{ $isSelected ? 'bg-green-50' : '' }}"
                >
                    <span class="text-xs text-gray-500 uppercase tracking-wide">{{ $day->format('D') }}</span>
                    <span class="mt-1 w-8 h-8 flex items-center justify-center rounded-full text-sm font-medium
                        {{ $isToday ? 'bg-green-600 text-white' : ($isSelected ? 'text-green-700 font-semibold' : 'text-gray-700') }}">
                        {{ $day->format('j') }}
                    </span>
                    <span class="mt-1 text-xs {{ $total > 0 ? 'text-gray-600' : 'text-gray-300' }}">
                        {{ $total > 0 ? number_format($total, 1) : '–' }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Day header --}}
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-800">
            {{ \Carbon\Carbon::parse($selectedDate)->format('l, j F Y') }}
        </h2>
        <button
            wire:click="openNewModal"
            class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition"
            title="New entry (N)"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Track time
        </button>
    </div>

    {{-- Entries list --}}
    @if ($dayEntries->isEmpty())
        <div class="text-center py-16 text-gray-400">
            <p class="text-base">No time entries for this day.</p>
            <p class="text-sm mt-1">Press <kbd class="bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs font-mono">N</kbd> or click Track time to add one.</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($dayEntries as $entry)
                <div class="bg-white rounded-lg border border-gray-200 px-4 py-3 flex items-start gap-4">
                    {{-- Colour band --}}
                    <div class="mt-1 w-1 self-stretch rounded-full flex-shrink-0" style="background-color: {{ $entry->task->colour }}"></div>

                    {{-- Main content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline gap-2 flex-wrap">
                            <span class="font-semibold text-gray-900">{{ $entry->project->name }}</span>
                            <span class="text-gray-400 text-sm">{{ $entry->project->client->name }}</span>
                        </div>
                        <div class="text-sm text-gray-600 mt-0.5">{{ $entry->task->name }}</div>
                        @if ($entry->notes)
                            <div class="text-xs text-gray-400 mt-1">{{ $entry->notes }}</div>
                        @endif
                    </div>

                    {{-- Hours + running indicator --}}
                    <div class="flex items-center gap-3 flex-shrink-0">
                        @if ($entry->is_running)
                            <span class="inline-flex items-center gap-1 text-green-600 text-sm font-medium">
                                <span class="animate-pulse w-2 h-2 bg-green-500 rounded-full"></span>
                                Running
                            </span>
                        @endif
                        <span class="text-gray-700 font-medium tabular-nums">{{ number_format((float) $entry->hours, 2) }}h</span>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1 flex-shrink-0">
                        @if ($entry->is_running)
                            <button
                                wire:click="stopTimer({{ $entry->id }})"
                                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition"
                                title="Stop timer"
                            >
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M6 6h12v12H6z"/>
                                </svg>
                            </button>
                        @else
                            <button
                                wire:click="startTimer({{ $entry->id }})"
                                class="p-1.5 text-gray-400 hover:text-green-600 hover:bg-green-50 rounded transition"
                                title="Start timer"
                            >
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z"/>
                                </svg>
                            </button>
                        @endif
                        <button
                            wire:click="openEditModal({{ $entry->id }})"
                            class="p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded transition"
                            title="Edit"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button
                            wire:click="deleteEntry({{ $entry->id }})"
                            wire:confirm="Delete this time entry?"
                            class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition"
                            title="Delete"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Day / week totals --}}
    <div class="mt-4 flex justify-end gap-6 text-sm text-gray-500">
        <span>Day: <strong class="text-gray-800">{{ number_format($dayTotal, 2) }}h</strong></span>
        <span>Week: <strong class="text-gray-800">{{ number_format($weekTotal, 2) }}h</strong></span>
    </div>

    {{-- Submit week stub --}}
    <div class="mt-4 flex justify-end">
        <button
            disabled
            title="Coming soon"
            class="text-sm text-gray-400 border border-gray-200 px-4 py-2 rounded-lg cursor-not-allowed"
        >Submit week for approval</button>
    </div>

    {{-- ============================================================
         Entry modal
    ============================================================ --}}
    @if ($showModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
            @keydown.escape.window="$wire.closeModal()"
        >
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4" @click.stop>
                {{-- Modal header --}}
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-900">
                        {{ $editingEntryId ? 'Edit entry' : 'Track time' }}
                    </h3>
                    <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-4 space-y-4">
                    @if ($selectedProjectId === null)
                        {{-- Step 1: Project picker --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                            <input
                                type="text"
                                wire:model.live="projectSearch"
                                placeholder="Search projects…"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                autofocus
                            />
                        </div>
                        <div class="max-h-64 overflow-y-auto space-y-1">
                            @php
                                $grouped = $projectsForPicker
                                    ->when($projectSearch !== '', fn ($c) => $c->filter(
                                        fn ($p) => str_contains(strtolower($p->name), strtolower($projectSearch))
                                            || str_contains(strtolower($p->client->name), strtolower($projectSearch))
                                    ))
                                    ->groupBy(fn ($p) => $p->client->name)
                                    ->sortKeys();
                            @endphp
                            @forelse ($grouped as $clientName => $projects)
                                <div>
                                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-2 py-1">{{ $clientName }}</div>
                                    @foreach ($projects as $project)
                                        <button
                                            wire:click="selectProject({{ $project->id }})"
                                            class="w-full text-left px-3 py-2 rounded-lg text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition"
                                        >{{ $project->name }}</button>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-sm text-gray-400 px-2 py-4 text-center">No projects found.</p>
                            @endforelse
                        </div>

                    @elseif ($selectedTaskId === null)
                        {{-- Step 2: Task picker --}}
                        <div class="flex items-center gap-2">
                            <button wire:click="backToProjectPicker" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <span class="font-medium text-gray-800 text-sm">{{ $selectedProject?->name }}</span>
                        </div>
                        <div>
                            <input
                                type="text"
                                wire:model.live="taskSearch"
                                placeholder="Search tasks…"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                autofocus
                            />
                        </div>
                        <div class="max-h-60 overflow-y-auto space-y-1">
                            @php
                                $assignedTasks = $selectedProject?->tasks ?? collect();
                                $billableTasks = $assignedTasks->filter(fn ($t) => (bool) $t->pivot->getAttribute('is_billable'));
                                $nonBillableTasks = $assignedTasks->reject(fn ($t) => (bool) $t->pivot->getAttribute('is_billable'));

                                if ($taskSearch !== '') {
                                    $billableTasks = $billableTasks->filter(fn ($t) => str_contains(strtolower($t->name), strtolower($taskSearch)));
                                    $nonBillableTasks = $nonBillableTasks->filter(fn ($t) => str_contains(strtolower($t->name), strtolower($taskSearch)));
                                }
                            @endphp
                            @if ($billableTasks->isNotEmpty())
                                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-2 py-1">Billable</div>
                                @foreach ($billableTasks->sortBy('name') as $task)
                                    <button
                                        wire:click="selectTask({{ $task->id }})"
                                        class="w-full text-left px-3 py-2 rounded-lg text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition flex items-center gap-2"
                                    >
                                        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $task->colour }}"></span>
                                        {{ $task->name }}
                                    </button>
                                @endforeach
                            @endif
                            @if ($nonBillableTasks->isNotEmpty())
                                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-2 py-1 {{ $billableTasks->isNotEmpty() ? 'mt-2' : '' }}">Non-billable</div>
                                @foreach ($nonBillableTasks->sortBy('name') as $task)
                                    <button
                                        wire:click="selectTask({{ $task->id }})"
                                        class="w-full text-left px-3 py-2 rounded-lg text-sm text-gray-800 hover:bg-gray-50 transition flex items-center gap-2"
                                    >
                                        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $task->colour }}"></span>
                                        {{ $task->name }}
                                    </button>
                                @endforeach
                            @endif
                            @if ($billableTasks->isEmpty() && $nonBillableTasks->isEmpty())
                                <p class="text-sm text-gray-400 px-2 py-4 text-center">No tasks found.</p>
                            @endif
                        </div>

                    @else
                        {{-- Step 3: Hours + notes --}}
                        @php $chosenTask = $selectedProject?->tasks->firstWhere('id', $selectedTaskId); @endphp
                        <div class="flex items-center gap-2 text-sm text-gray-600">
                            <button wire:click="backToProjectPicker" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <span class="font-medium text-gray-800">{{ $selectedProject?->name }}</span>
                            <span class="text-gray-400">·</span>
                            <span>{{ $chosenTask?->name }}</span>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Hours</label>
                            <input
                                type="text"
                                wire:model="hoursInput"
                                placeholder="e.g. 1.5 or 1:30 or 90m"
                                class="w-full border {{ $hoursError ? 'border-red-400' : 'border-gray-300' }} rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                autofocus
                                wire:keydown.enter="save"
                            />
                            @if ($hoursError)
                                <p class="text-red-500 text-xs mt-1">{{ $hoursError }}</p>
                            @endif
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                            <textarea
                                wire:model="notes"
                                rows="3"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"
                                placeholder="What did you work on?"
                            ></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input
                                type="date"
                                wire:model="entryDate"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                            />
                        </div>
                    @endif
                </div>

                {{-- Modal footer --}}
                @if ($selectedTaskId !== null)
                    <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-100">
                        <button
                            wire:click="closeModal"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-200 rounded-lg hover:bg-gray-50 transition"
                        >Cancel</button>
                        <button
                            wire:click="save"
                            class="px-4 py-2 text-sm font-medium bg-green-600 hover:bg-green-700 text-white rounded-lg transition"
                        >Save entry</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- 60-second poll for running timers --}}
    <div wire:poll.60000ms="refreshForTimer" class="hidden"></div>
</div>
