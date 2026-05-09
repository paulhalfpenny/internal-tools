<div>
    @if($isImpersonating)
        <div class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between">
            <div class="text-sm text-amber-900">
                <span class="font-semibold">Editing timesheet for {{ $viewedUser->name }}</span>
                <span class="text-amber-700 ml-2">({{ $viewedUser->email }})</span>
            </div>
            <a href="{{ route('admin.timesheets') }}" class="text-sm text-amber-900 hover:underline">← Back to admin index</a>
        </div>
    @elseif($isReadOnly)
        <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-lg flex items-center justify-between">
            <div class="text-sm text-blue-900">
                <span class="font-semibold">Viewing timesheet for {{ $viewedUser->name }}</span>
                <span class="text-blue-700 ml-2">({{ $viewedUser->email }})</span>
                <span class="text-blue-700 ml-2">— read-only</span>
            </div>
            <a href="{{ route('timesheet') }}" class="text-sm text-blue-900 hover:underline">← Back to my timesheet</a>
        </div>
    @endif

    @if(session('week_saved'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded text-sm text-green-700">Saved.</div>
    @endif

    {{-- Day header --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            <div class="inline-flex items-center">
                <button wire:click="previousWeek"
                        class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm"
                        title="Previous week">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button wire:click="nextWeek"
                        class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm ml-1"
                        title="Next week">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <h2 class="text-lg font-semibold text-gray-800">
                {{ $weekStart->format('j M') }} – {{ $weekStart->addDays(6)->format('j M Y') }}
            </h2>
        </div>
        <div class="flex items-center gap-2">
            {{-- Date picker --}}
            <div class="relative" x-data>
                <button type="button"
                        @click="$refs.datePicker.showPicker?.() ?? $refs.datePicker.click()"
                        title="Pick a date"
                        style="width:2.25rem; height:2.25rem;"
                        class="inline-flex items-center justify-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 rounded-lg transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </button>
                <input x-ref="datePicker" type="date" value="{{ $selectedDate }}"
                       @change="$wire.set('selectedDate', $event.target.value)"
                       class="absolute inset-0 opacity-0" style="pointer-events:none;" aria-label="Pick a date"/>
            </div>

            @unless(\Carbon\Carbon::parse($selectedDate)->isSameWeek(\Carbon\Carbon::today()))
                <button wire:click="goToToday"
                        class="inline-flex items-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                    This week
                </button>
            @endunless

            {{-- Day / Week toggle --}}
            @php
                $dayUrl = $isImpersonating || $isReadOnly
                    ? route(request()->routeIs('admin.*') ? 'admin.timesheets.user' : 'team.timesheet', ['user' => $viewedUser, 'date' => $selectedDate])
                    : route('timesheet', ['date' => $selectedDate]);
            @endphp
            <div class="inline-flex bg-gray-100 rounded-lg p-1">
                <a href="{{ $dayUrl }}"
                   class="px-4 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 rounded-md">Day</a>
                <span class="px-4 py-1.5 text-sm font-medium bg-white text-gray-900 rounded-md shadow-sm">Week</span>
            </div>

            @if($teamMembers->isNotEmpty())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open"
                            class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                        Team Timesheets
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute right-0 top-full mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 overflow-y-auto"
                         style="max-height: 320px;">
                        <div class="px-3 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide">Direct reports</div>
                        @foreach($teamMembers as $member)
                            <a href="{{ route('team.timesheet.week', ['user' => $member->id, 'date' => $selectedDate]) }}"
                               class="block px-3 py-2 text-sm text-gray-800 hover:bg-gray-50 hover:text-gray-900">{{ $member->name }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Week table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600" style="min-width: 280px;">Project &amp; task</th>
                    @foreach($weekDays as $day)
                        <th class="px-2 py-3 text-center font-medium text-gray-600 {{ $day->isToday() ? 'bg-green-50' : '' }}" style="min-width: 80px;">
                            <div class="text-xs uppercase tracking-wide">{{ $day->format('D') }}</div>
                            <div class="text-sm">{{ $day->format('j M') }}</div>
                        </th>
                    @endforeach
                    <th class="px-2 py-3 text-right font-medium text-gray-600" style="min-width: 70px;">Total</th>
                    @unless($isReadOnly)
                        <th class="px-2 py-3" style="width: 36px;"></th>
                    @endunless
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    @php
                        $rowTotal = 0.0;
                        $cells = $cellValues[$row['key']] ?? $row['cells'];
                        for ($i = 0; $i < 7; $i++) {
                            $raw = trim((string) ($cells[$i] ?? ''));
                            if ($raw !== '') {
                                try { $rowTotal += \App\Domain\TimeTracking\HoursParser::parse($raw); } catch (\InvalidArgumentException) {}
                            }
                        }
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $row['project_name'] }}
                                @if($row['client_name'])
                                    <span class="text-xs text-gray-400 ml-1">({{ $row['client_name'] }})</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $row['task_name'] }}</div>
                            @if(! empty($row['asana_task_name']))
                                <div class="text-xs text-gray-400 mt-0.5 italic truncate" title="{{ $row['asana_task_name'] }}">↳ {{ $row['asana_task_name'] }}</div>
                            @endif
                        </td>
                        @for($i = 0; $i < 7; $i++)
                            <td class="px-2 py-2 {{ $weekDays[$i]->isToday() ? 'bg-green-50' : '' }}">
                                @if($isReadOnly)
                                    <div class="text-center text-sm text-gray-700 tabular-nums">
                                        {{ trim((string) ($cells[$i] ?? '')) ?: '—' }}
                                    </div>
                                @else
                                    <input type="text"
                                           wire:model="cellValues.{{ $row['key'] }}.{{ $i }}"
                                           value="{{ $cells[$i] ?? '' }}"
                                           placeholder="—"
                                           class="w-full text-center text-sm tabular-nums border border-gray-200 rounded px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                @endif
                            </td>
                        @endfor
                        <td class="px-2 py-3 text-right text-sm font-medium tabular-nums text-gray-900">
                            {{ $rowTotal > 0 ? \App\Domain\TimeTracking\HoursFormatter::asTime((float) $rowTotal) : '—' }}
                        </td>
                        @unless($isReadOnly)
                            <td class="px-2 py-3 text-center">
                                <button wire:click="removeRow('{{ $row['key'] }}')"
                                        wire:confirm="Remove this row? Any time logged against it this week will be deleted."
                                        class="text-gray-300 hover:text-red-600 transition" title="Remove row">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isReadOnly ? 9 : 10 }}" class="px-4 py-12 text-center text-gray-400 text-sm">
                            No time logged this week.
                            @unless($isReadOnly)
                                Click <strong>+ Add row</strong> to start.
                            @endunless
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
                <tr>
                    <td class="px-4 py-3 text-sm font-medium text-gray-700">Daily totals</td>
                    @for($i = 0; $i < 7; $i++)
                        <td class="px-2 py-3 text-center text-sm font-medium tabular-nums text-gray-900 {{ $weekDays[$i]->isToday() ? 'bg-green-100' : '' }}">
                            {{ $dayTotals[$i] > 0 ? \App\Domain\TimeTracking\HoursFormatter::asTime((float) $dayTotals[$i]) : '—' }}
                        </td>
                    @endfor
                    <td class="px-2 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">
                        {{ $weekTotal > 0 ? \App\Domain\TimeTracking\HoursFormatter::asTime((float) $weekTotal) : '—' }}
                    </td>
                    @unless($isReadOnly)
                        <td></td>
                    @endunless
                </tr>
            </tfoot>
        </table>
    </div>

    @unless($isReadOnly)
        <div class="mt-4 flex items-center gap-3">
            <button wire:click="openAddRowModal"
                    class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add row
            </button>
            <button wire:click="save"
                    class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                Save
            </button>
        </div>
    @endunless

    {{-- Add row modal — mirrors the Day view's Track Time modal --}}
    <div x-show="$wire.showAddRowModal" style="display:none">
        <div
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/40"
            style="padding-top: 22vh"
            @keydown.escape.window="$wire.closeAddRowModal()"
        >
            <div
                class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4"
                x-data="{
                    projectOpen: false,
                    taskOpen: false,
                    asanaTaskOpen: false,
                    asanaTaskSearch: '',
                    projectSearch: '',
                    selectedProjectId: $wire.newRowProjectId,
                    selectedTaskId: $wire.newRowTaskId,
                    selectedAsanaTaskGid: $wire.newRowAsanaTaskGid ?? '',
                    projects: {{ Js::from($projectsForPicker) }},
                    asanaTasksByProject: {{ Js::from($asanaTasksByProject) }},
                    asanaAvailable: {{ $asanaAvailable ? 'true' : 'false' }},
                    init() {
                        this.$watch('$wire.newRowProjectId', v => this.selectedProjectId = v);
                        this.$watch('$wire.newRowTaskId', v => this.selectedTaskId = v);
                        this.$watch('$wire.newRowAsanaTaskGid', v => this.selectedAsanaTaskGid = v ?? '');

                        this.$watch('$wire.showAddRowModal', (open) => {
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
                    get asanaProjectGid() {
                        return this.selectedProject?.asana_project_gid ?? null;
                    },
                    get asanaRequired() {
                        return !!this.asanaProjectGid;
                    },
                    get asanaTasks() {
                        if (!this.asanaProjectGid) return [];
                        return this.asanaTasksByProject[this.asanaProjectGid] ?? [];
                    },
                    get filteredAsanaTasks() {
                        const q = this.asanaTaskSearch.toLowerCase();
                        if (!q) return this.asanaTasks;
                        return this.asanaTasks.filter(t => t.name.toLowerCase().includes(q));
                    },
                    get selectedAsanaTask() {
                        return this.asanaTasks.find(t => t.gid === this.selectedAsanaTaskGid) ?? null;
                    },
                    get groupedProjects() {
                        const q = this.projectSearch.toLowerCase();
                        const filtered = q
                            ? this.projects.filter(p => p.name.toLowerCase().includes(q) || (p.client_name ?? '').toLowerCase().includes(q))
                            : this.projects;
                        const groups = {};
                        filtered.forEach(p => { (groups[p.client_name || '—'] ??= []).push(p); });
                        return Object.entries(groups).sort(([a],[b]) => a.localeCompare(b));
                    },
                    pickProject(id) {
                        this.selectedProjectId = id;
                        this.selectedTaskId = null;
                        this.selectedAsanaTaskGid = '';
                        $wire.set('newRowProjectId', id);
                        $wire.set('newRowTaskId', null);
                        $wire.set('newRowAsanaTaskGid', '');
                        this.projectSearch = '';
                        this.projectOpen = false;
                    },
                    pickTask(id) {
                        this.selectedTaskId = id;
                        $wire.set('newRowTaskId', id);
                        this.taskOpen = false;
                    },
                    pickAsanaTask(gid) {
                        this.selectedAsanaTaskGid = gid;
                        $wire.set('newRowAsanaTaskGid', gid);
                        this.asanaTaskOpen = false;
                    },
                }"
                @click.stop
            >
                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-100 text-center relative">
                    <h3 class="font-semibold text-gray-900 text-base">
                        Add row to this timesheet
                    </h3>
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

                    {{-- Asana task (only when project is linked) --}}
                    <template x-if="asanaRequired">
                        <div class="relative z-20">
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
                                            <span class="text-sm font-medium text-gray-900 truncate" x-text="selectedAsanaTask.name"></span>
                                        </template>
                                        <template x-if="!selectedAsanaTask">
                                            <span class="text-gray-400 text-sm">Select an Asana task…</span>
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
                                                    class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition truncate"
                                                    x-text="task.name"
                                                ></button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            @error('newRowAsanaTaskGid')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
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

                </div>

                {{-- Modal footer --}}
                <div class="flex items-center px-6 py-4 border-t border-gray-100">
                    <button
                        wire:click="addRow"
                        class="px-5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-full transition"
                    >Save row</button>
                    <button
                        wire:click="closeAddRowModal"
                        class="ml-3 px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-full transition"
                    >Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>
