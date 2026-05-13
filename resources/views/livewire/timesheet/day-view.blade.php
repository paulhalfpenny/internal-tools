<div
    x-data="{}"
    {{-- Global 'n' shortcut opens a new time entry. Guard against firing while
         the user is typing into a form field — otherwise typing the letter 'n'
         in the notes textarea (etc.) re-opens the modal and resets the form. --}}
    @keydown.n.window="if (!['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName) && !$event.target.isContentEditable) $wire.openNewModal()"
>
    @if($isImpersonating)
        <div class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between">
            <div class="text-sm text-amber-900">
                <span class="font-semibold">Editing timesheet for {{ $viewedUser->name }}</span>
                <span class="text-amber-700 ml-2">({{ $viewedUser->email }})</span>
            </div>
            <a href="{{ $backUrl }}" class="text-sm text-amber-900 hover:underline">← {{ $backLabel }}</a>
        </div>
    @elseif($isReadOnly)
        <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-lg flex items-center justify-between">
            <div class="text-sm text-blue-900">
                <span class="font-semibold">Viewing timesheet for {{ $viewedUser->name }}</span>
                <span class="text-blue-700 ml-2">({{ $viewedUser->email }})</span>
                <span class="text-blue-700 ml-2">— read-only</span>
            </div>
            <a href="{{ $backUrl }}" class="text-sm text-blue-900 hover:underline">← {{ $backLabel }}</a>
        </div>
    @endif

    {{-- Day header --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            @unless($isReadOnly)
                <button
                    @click="$wire.showModal = true; $wire.openNewModal()"
                    class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition"
                    title="New entry (N)"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Track time
                </button>
            @endunless
            <div class="inline-flex items-center">
                <button
                    wire:click="previousWeek"
                    class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm"
                    title="Previous week"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <button
                    wire:click="nextWeek"
                    class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm ml-1"
                    title="Next week"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
            <h2 class="text-lg font-semibold text-gray-800">
                {{ \Carbon\Carbon::parse($selectedDate)->format('l, j F Y') }}
            </h2>
        </div>
        <div class="flex items-center gap-2">
            {{-- Date picker — calendar icon opens the browser's native date input --}}
            <div class="relative" x-data>
                <button
                    type="button"
                    @click="$refs.datePicker.showPicker?.() ?? $refs.datePicker.click()"
                    title="Pick a date"
                    style="width:2.25rem; height:2.25rem;"
                    class="inline-flex items-center justify-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 rounded-lg transition"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </button>
                <input
                    x-ref="datePicker"
                    type="date"
                    value="{{ $selectedDate }}"
                    @change="$wire.selectDate($event.target.value)"
                    class="absolute inset-0 opacity-0"
                    style="pointer-events:none;"
                    aria-label="Pick a date"
                />
            </div>

            @if($selectedDate !== \Carbon\Carbon::today()->toDateString())
                <button
                    wire:click="selectDate('{{ \Carbon\Carbon::today()->toDateString() }}')"
                    class="inline-flex items-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition"
                >
                    Today
                </button>
            @endif

            {{-- Day / Week toggle --}}
            @php
                $weekUrl = $isImpersonating
                    ? route('admin.timesheets.user.week', ['user' => $viewedUser, 'date' => $selectedDate])
                    : ($isReadOnly
                        ? route('team.timesheet.week', ['user' => $viewedUser, 'date' => $selectedDate])
                        : route('timesheet.week', ['date' => $selectedDate]));
            @endphp
            <div class="inline-flex bg-gray-100 rounded-lg p-1">
                <span class="px-4 py-1.5 text-sm font-medium bg-white text-gray-900 rounded-md shadow-sm">Day</span>
                <a href="{{ $weekUrl }}"
                   class="px-4 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 rounded-md">Week</a>
            </div>

            @if($teamMembers->isNotEmpty())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button
                        type="button"
                        @click="open = !open"
                        class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition"
                    >
                        Team Timesheets
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        class="absolute right-0 top-full mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 overflow-y-auto"
                        style="max-height: 320px;"
                    >
                        <div class="px-3 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide">Direct reports</div>
                        @foreach($teamMembers as $member)
                            <a
                                href="{{ route('team.timesheet', $member->id) }}"
                                class="block px-3 py-2 text-sm text-gray-800 hover:bg-gray-50 hover:text-gray-900"
                            >{{ $member->name }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

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
                        {{ $total > 0 ? \App\Domain\TimeTracking\HoursFormatter::asTime($total) : '–' }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Entries list --}}
    @if (session('copy_rows_message'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded text-sm text-green-700">
            {{ session('copy_rows_message') }}
        </div>
    @endif
    @if ($dayEntries->isEmpty())
        <div
            class="rounded-xl bg-gray-100 flex items-center justify-center px-8 text-center"
            style="min-height: 350px"
            x-data="{ song: null }"
            x-init="fetch('/timesheet/song/{{ $selectedDate }}').then(r => r.json()).then(d => song = d)"
        >
            <div x-show="song">
                <p class="text-lg italic text-gray-500" x-text="'&quot;' + (song?.song_name ?? '') + '&quot;'"></p>
                <p class="mt-2 text-sm text-gray-400" x-text="(song?.album ?? '') + ' · ' + (song?.year ?? '') + ' · Depeche Mode'"></p>
            </div>
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
                        @if ($entry->asana_task_gid)
                            <a
                                href="https://app.asana.com/0/0/{{ $entry->asana_task_gid }}/f"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-[#E1236C] mt-1 group"
                                title="Open in Asana"
                            >
                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M18.78 12.653a4.105 4.105 0 1 0-.001 8.21 4.105 4.105 0 0 0 .001-8.21Zm-13.56 0a4.105 4.105 0 1 0 0 8.211 4.105 4.105 0 0 0 0-8.211Zm10.885-7.582a4.105 4.105 0 1 1-8.21 0 4.105 4.105 0 0 1 8.21 0Z"/>
                                </svg>
                                <span class="truncate">{{ $entry->asanaTask?->name ?? 'Linked Asana task' }}</span>
                                <svg class="w-3 h-3 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                            </a>
                        @endif
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
                        <span class="text-gray-700 font-medium tabular-nums">{{ \App\Domain\TimeTracking\HoursFormatter::asTime((float) $entry->hours) }}</span>
                    </div>

                    {{-- Actions --}}
                    @unless($isReadOnly)
                    <div class="flex items-center gap-1 flex-shrink-0">
                        @unless($isImpersonating)
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
                        @endunless
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
                    @endunless
                </div>
            @endforeach
        </div>
    @endif

    {{-- Day / week totals --}}
    <div class="mt-4 flex items-center justify-between gap-4 text-sm text-gray-500">
        <div>
            @if ($canCopyFromPrior)
                <button
                    wire:click="copyRowsFromMostRecent"
                    class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                    </svg>
                    Copy rows from most recent timesheet
                </button>
            @endif
        </div>
        <div class="flex gap-6">
            <span>Day: <strong class="text-gray-800">{{ \App\Domain\TimeTracking\HoursFormatter::asTime((float) $dayTotal) }}</strong></span>
            <span>Week: <strong class="text-gray-800">{{ \App\Domain\TimeTracking\HoursFormatter::asTime((float) $weekTotal) }}</strong></span>
        </div>
    </div>


    {{-- ============================================================
         Entry modal
    ============================================================ --}}
    <div x-show="$wire.showModal" style="display:none">

        {{-- ============================================================
             Calendar sidebar — slides in from the left over the page
        ============================================================ --}}
        <div
            x-data="{ open: @entangle('showCalendarPanel') }"
            x-show="open"
            x-transition:enter="transition-transform ease-out duration-300"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition-transform ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="fixed inset-y-0 left-0 z-[60] w-80 bg-white shadow-2xl flex flex-col"
            style="display:none"
        >
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <div>
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Calendar events for</div>
                    <div class="font-semibold text-gray-900 text-sm mt-0.5">{{ \Carbon\Carbon::parse($selectedDate)->format('l, j M') }}</div>
                </div>
                <button wire:click="closeCalendarPanel" class="text-gray-400 hover:text-gray-600 p-1 rounded transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="overflow-y-auto flex-1 p-4 space-y-2">
                @if ($calendarError === 'no_token')
                    <div class="text-center py-8 px-3">
                        <svg class="w-8 h-8 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <p class="text-sm text-gray-500 mb-3">Calendar access requires signing in with Google.</p>
                        <a href="{{ route('auth.google') }}" class="text-sm text-blue-600 hover:underline">Re-sign in to grant access →</a>
                    </div>
                @elseif ($calendarError === 'empty' || empty($calendarEvents))
                    <div class="text-center py-8">
                        <p class="text-sm text-gray-400">No events on this day.</p>
                    </div>
                @else
                    @foreach ($calendarEvents as $event)
                        @php
                            $used = in_array(strtolower($event['title']), $usedEventTitles, true);
                            $hoursLabel = $event['hours'] == 1.0 ? '1 hour'
                                : \App\Domain\TimeTracking\HoursFormatter::asTime((float) $event['hours']);
                        @endphp
                        <button
                            @if (! $used) wire:click="pullFromCalendarEvent('{{ addslashes($event['title']) }}', {{ $event['hours'] }})" @endif
                            {{ $used ? 'disabled' : '' }}
                            class="w-full text-left p-3 rounded-lg border transition
                                {{ $used
                                    ? 'border-gray-100 bg-gray-50 cursor-default'
                                    : 'border-gray-200 bg-white hover:border-green-300 hover:bg-green-50 cursor-pointer' }}"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-semibold text-sm {{ $used ? 'text-gray-400' : 'text-gray-900' }} leading-snug">
                                    {{ $event['title'] }}
                                </div>
                                @if ($used)
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                @endif
                            </div>
                            <div class="text-xs {{ $used ? 'text-gray-400' : 'text-gray-500' }} mt-0.5">
                                {{ $event['start_formatted'] }} – {{ $event['end_formatted'] }}
                            </div>
                            <div class="text-xs {{ $used ? 'text-gray-400' : 'text-gray-500' }}">
                                {{ $hoursLabel }}
                            </div>
                        </button>
                    @endforeach
                @endif
            </div>
        </div>

        {{-- Modal backdrop + centred dialog --}}
        <div
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/40"
            style="padding-top: 22vh"
            @click.self="$wire.closeModal()"
            @keydown.escape.window="$wire.closeModal()"
        >
            <div
                class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4"
                x-data="{
                    projectOpen: false,
                    taskOpen: false,
                    asanaTaskOpen: false,
                    asanaTaskSearch: '',
                    projectSearch: '',
                    selectedProjectId: $wire.selectedProjectId,
                    selectedTaskId: $wire.selectedTaskId,
                    selectedAsanaTaskGid: $wire.selectedAsanaTaskGid ?? '',
                    liveHoursInput: $wire.hoursInput ?? '',
                    projects: {{ Js::from($projectsForPicker) }},
                    asanaTasksByProject: {{ Js::from($asanaTasksByProject) }},
                    asanaAvailable: {{ $asanaAvailable ? 'true' : 'false' }},
                    init() {
                        // $wire is the source of truth; mirror it into Alpine state on every Livewire-driven change
                        // (calendar pull, edit modal, calendar association auto-fill, etc.) so Alpine never clobbers.
                        this.$watch('$wire.selectedProjectId', v => this.selectedProjectId = v);
                        this.$watch('$wire.selectedTaskId', v => this.selectedTaskId = v);
                        this.$watch('$wire.selectedAsanaTaskGid', v => this.selectedAsanaTaskGid = v ?? '');
                        this.$watch('$wire.hoursInput', v => this.liveHoursInput = v ?? '');

                        this.$watch('$wire.showModal', (open) => {
                            if (open) {
                                this.projectOpen = false;
                                this.taskOpen = false;
                                this.asanaTaskOpen = false;
                                this.projectSearch = '';
                                this.asanaTaskSearch = '';
                            }
                        });
                    },
                    get selectedProject() {
                        return this.projects.find(p => p.id === this.selectedProjectId) ?? null;
                    },
                    get selectedTask() {
                        return this.selectedProject?.tasks.find(t => t.id === this.selectedTaskId) ?? null;
                    },
                    get asanaBoardGids() {
                        return this.selectedProject?.asana_project_gids ?? [];
                    },
                    get asanaRequired() {
                        if (this.asanaBoardGids.length === 0) return false;
                        if ($wire.lastCalendarPullTitle) return false;
                        return this.selectedProject?.asana_task_required ?? true;
                    },
                    get asanaTasks() {
                        if (this.asanaBoardGids.length === 0) return [];
                        const out = [];
                        for (const gid of this.asanaBoardGids) {
                            for (const t of (this.asanaTasksByProject[gid] ?? [])) out.push(t);
                        }
                        return out;
                    },
                    get filteredAsanaTasks() {
                        const q = this.asanaTaskSearch.toLowerCase();
                        if (!q) return this.asanaTasks;
                        return this.asanaTasks.filter(t =>
                            t.name.toLowerCase().includes(q) ||
                            (t.board_name ?? '').toLowerCase().includes(q)
                        );
                    },
                    get showAsanaBoardLabel() {
                        return this.asanaBoardGids.length > 1;
                    },
                    get selectedAsanaTask() {
                        return this.asanaTasks.find(t => t.gid === this.selectedAsanaTaskGid) ?? null;
                    },
                    get groupedProjects() {
                        const q = this.projectSearch.toLowerCase();
                        const filtered = q
                            ? this.projects.filter(p => p.name.toLowerCase().includes(q) || p.client_name.toLowerCase().includes(q))
                            : this.projects;
                        const groups = {};
                        filtered.forEach(p => { (groups[p.client_name] ??= []).push(p); });
                        return Object.entries(groups).sort(([a],[b]) => a.localeCompare(b));
                    },
                    pickProject(id) {
                        this.selectedProjectId = id;
                        this.selectedTaskId = null;
                        this.selectedAsanaTaskGid = '';
                        $wire.selectedProjectId = id;
                        $wire.selectedTaskId = null;
                        $wire.selectedAsanaTaskGid = '';
                        this.projectSearch = '';
                        this.projectOpen = false;
                    },
                    pickTask(id) {
                        this.selectedTaskId = id;
                        $wire.selectedTaskId = id;
                        this.taskOpen = false;
                    },
                    pickAsanaTask(gid) {
                        this.selectedAsanaTaskGid = gid;
                        $wire.selectedAsanaTaskGid = gid;
                        this.asanaTaskOpen = false;
                    },
                    doSave(isTimer) {
                        isTimer ? $wire.startTimerFromModal() : $wire.save();
                    },
                    get isTimerMode() {
                        return (this.liveHoursInput ?? '').trim() === '';
                    }
                }"
                @click.stop
            >
                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-100 text-center relative">
                    <h3 class="font-semibold text-gray-900 text-base">
                        @if ($editingEntryId)
                            Edit entry
                        @else
                            New time entry for {{ \Carbon\Carbon::parse($selectedDate)->format('l, j M') }}
                        @endif
                    </h3>
                    <button
                        type="button"
                        wire:click="closeModal"
                        aria-label="Close"
                        class="absolute top-3 right-3 p-1.5 rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-3">

                    {{-- Project / Task label --}}
                    <div class="text-sm font-semibold text-gray-700">Project / Task</div>

                    {{-- Project dropdown --}}
                    <div class="relative z-30">
                        <button
                            type="button"
                            @click="projectOpen = !projectOpen; taskOpen = false"
                            class="w-full flex items-center justify-between border border-gray-300 rounded-lg px-4 py-3 text-left bg-white hover:border-gray-400 transition focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            <template x-if="selectedProject">
                                <div class="min-w-0">
                                    <div class="text-xs text-gray-500 leading-none mb-0.5" x-text="selectedProject.client_name"></div>
                                    <div class="font-semibold text-gray-900 text-sm leading-none" x-text="selectedProject.name"></div>
                                </div>
                            </template>
                            <template x-if="!selectedProject">
                                <span class="text-gray-400 text-sm">Select a project…</span>
                            </template>
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div
                            x-show="projectOpen"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            @click.outside="projectOpen = false"
                            class="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg"
                            style="display: none"
                        >
                            <div class="p-2 border-b border-gray-100">
                                <input
                                    type="text"
                                    x-model="projectSearch"
                                    placeholder="Search projects…"
                                    class="w-full text-sm px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                                    x-init="$el.focus()"
                                />
                            </div>
                            <div class="max-h-60 overflow-y-auto py-1">
                                <template x-if="groupedProjects.length === 0">
                                    <p class="text-sm text-gray-400 px-3 py-4 text-center">No projects found.</p>
                                </template>
                                <template x-for="[clientName, projects] in groupedProjects" :key="clientName">
                                    <div>
                                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-3 py-1.5 mt-1" x-text="clientName"></div>
                                        <template x-for="project in projects" :key="project.id">
                                            <button
                                                type="button"
                                                @click="pickProject(project.id)"
                                                class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition"
                                                x-text="project.name"
                                            ></button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Asana task picker — shown whenever the project has linked boards.
                         Required vs optional is gated by selectedProject.asana_task_required (see asanaRequired). --}}
                    <template x-if="asanaBoardGids.length > 0">
                        <div class="relative z-20">
                            <template x-if="!asanaRequired">
                                <p class="text-xs text-gray-500 mb-1">Asana task (optional)</p>
                            </template>

                            <template x-if="!asanaAvailable">
                                <div class="border border-yellow-200 bg-yellow-50 rounded-lg px-3 py-2 text-xs text-yellow-800">
                                    This project is linked to Asana, but no admin has connected the integration yet. Time can't be logged on it until they do.
                                </div>
                            </template>

                            <template x-if="asanaAvailable">
                                <div>
                                    <button
                                        type="button"
                                        @click="asanaTaskOpen = !asanaTaskOpen"
                                        class="w-full flex items-center justify-between border border-gray-300 rounded-lg px-4 py-2.5 text-left bg-white hover:border-gray-400 transition focus:outline-none focus:ring-2 focus:ring-green-500"
                                    >
                                        <template x-if="selectedAsanaTask">
                                            <div class="min-w-0">
                                                <div class="text-sm font-medium text-gray-900 truncate" x-text="selectedAsanaTask.name"></div>
                                                <template x-if="showAsanaBoardLabel && selectedAsanaTask.board_name">
                                                    <div class="text-xs text-gray-500 truncate" x-text="selectedAsanaTask.board_name"></div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!selectedAsanaTask">
                                            <span class="text-gray-400 text-sm" x-text="asanaRequired ? 'Select an Asana task…' : 'No Asana task'"></span>
                                        </template>
                                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>
                                    <div
                                        x-show="asanaTaskOpen"
                                        @click.outside="asanaTaskOpen = false"
                                        class="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg"
                                        style="display: none"
                                    >
                                        <div class="p-2 border-b border-gray-100">
                                            <input
                                                type="text"
                                                x-model="asanaTaskSearch"
                                                placeholder="Search Asana tasks…"
                                                class="w-full text-sm px-3 py-2 border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                                                x-init="$el.focus()"
                                            />
                                        </div>
                                        <div class="max-h-60 overflow-y-auto py-1">
                                            <template x-if="!asanaRequired">
                                                <button
                                                    type="button"
                                                    @click="pickAsanaTask('')"
                                                    class="w-full text-left px-4 py-2 text-sm text-gray-500 italic hover:bg-gray-50 transition"
                                                >— No Asana task —</button>
                                            </template>
                                            <template x-if="filteredAsanaTasks.length === 0">
                                                <p class="text-sm text-gray-400 px-3 py-4 text-center">
                                                    <template x-if="asanaTasks.length === 0">
                                                        <span>No Asana tasks cached for this project. An admin can refresh tasks on the project edit page.</span>
                                                    </template>
                                                    <template x-if="asanaTasks.length > 0">
                                                        <span>No tasks match.</span>
                                                    </template>
                                                </p>
                                            </template>
                                            <template x-for="task in filteredAsanaTasks" :key="task.gid">
                                                <button
                                                    type="button"
                                                    @click="pickAsanaTask(task.gid)"
                                                    class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition"
                                                >
                                                    <div class="truncate" x-text="task.name"></div>
                                                    <template x-if="showAsanaBoardLabel && task.board_name">
                                                        <div class="text-xs text-gray-500 truncate" x-text="task.board_name"></div>
                                                    </template>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            @error('selectedAsanaTaskGid')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                    </template>

                    {{-- Task dropdown --}}
                    <div class="relative z-10">
                        <button
                            type="button"
                            @click="if (selectedProjectId) { taskOpen = !taskOpen; projectOpen = false; }"
                            :class="selectedProjectId ? 'border-gray-300 bg-white hover:border-gray-400' : 'border-gray-200 bg-gray-50 cursor-not-allowed'"
                            class="w-full flex items-center justify-between border rounded-lg px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            <template x-if="selectedTask">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="'background:' + selectedTask.colour"></span>
                                    <span class="font-medium text-gray-900 text-sm" x-text="selectedTask.name"></span>
                                </div>
                            </template>
                            <template x-if="!selectedTask">
                                <span class="text-sm" :class="selectedProjectId ? 'text-gray-400' : 'text-gray-300'">Select a task…</span>
                            </template>
                            <svg class="w-4 h-4 flex-shrink-0 ml-2" :class="selectedProjectId ? 'text-gray-400' : 'text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div
                            x-show="taskOpen"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            @click.outside="taskOpen = false"
                            class="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg"
                            style="display: none"
                        >
                            <div class="max-h-60 overflow-y-auto py-1">
                                <template x-if="selectedProject">
                                    <div>
                                        <template x-if="selectedProject.tasks.filter(t => t.is_billable).length > 0">
                                            <div>
                                                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-3 py-1.5">Billable</div>
                                                <template x-for="task in [...selectedProject.tasks].filter(t => t.is_billable).sort((a,b) => a.name.localeCompare(b.name))" :key="task.id">
                                                    <button type="button" @click="pickTask(task.id)"
                                                        class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition flex items-center gap-2">
                                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="'background:' + task.colour"></span>
                                                        <span x-text="task.name"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="selectedProject.tasks.filter(t => !t.is_billable).length > 0">
                                            <div>
                                                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-3 py-1.5">Non-billable</div>
                                                <template x-for="task in [...selectedProject.tasks].filter(t => !t.is_billable).sort((a,b) => a.name.localeCompare(b.name))" :key="task.id">
                                                    <button type="button" @click="pickTask(task.id)"
                                                        class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-gray-50 hover:text-gray-700 transition flex items-center gap-2">
                                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="'background:' + task.colour"></span>
                                                        <span x-text="task.name"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="selectedProject.tasks.length === 0">
                                            <p class="text-sm text-gray-400 px-3 py-4 text-center">No tasks assigned.</p>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Notes + Time row --}}
                    <div class="flex gap-3 items-stretch">
                        <textarea
                            wire:model.blur="notes"
                            rows="1"
                            placeholder="Notes (optional)"
                            class="flex-1 border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none placeholder-gray-400"
                        ></textarea>
                        <div class="flex-shrink-0 w-24 flex flex-col">
                            <input
                                type="text"
                                wire:model.blur="hoursInput"
                                @input="liveHoursInput = $event.target.value"
                                placeholder="0.00"
                                class="w-full h-full border {{ $hoursError ? 'border-red-400' : 'border-gray-300' }} rounded-lg px-3 py-2.5 text-sm text-center tabular-nums focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400"
                            />
                            @if ($hoursError)
                                <p class="text-red-500 text-xs mt-1 text-center">{{ $hoursError }}</p>
                            @endif
                        </div>
                    </div>

                </div>

                {{-- Modal footer --}}
                <div class="flex items-center px-6 py-4 border-t border-gray-100">
                    <button
                        @click="doSave({{ $editingEntryId ? 'false' : ($isImpersonating ? 'false' : 'isTimerMode') }})"
                        class="px-5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-full transition"
                        x-text="{{ $editingEntryId || $isImpersonating ? "'Save entry'" : "isTimerMode ? 'Start timer' : 'Save entry'" }}"
                    ></button>
                    <button
                        wire:click="closeModal"
                        class="ml-3 px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-full transition"
                    >Cancel</button>
                </div>

            </div>

        </div>{{-- end modal backdrop --}}
    </div>{{-- end x-show wrapper --}}

    {{-- 60-second poll for running timers --}}
    <div wire:poll.60000ms="refreshForTimer" class="hidden"></div>
</div>
