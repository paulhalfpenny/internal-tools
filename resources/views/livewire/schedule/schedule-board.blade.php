@php
    $periodCount = count($periods);
    $gridTemplate = 'minmax(300px, 380px) repeat('.$periodCount.', minmax('.($scale === 'month' ? '132px' : '104px').', 1fr))';
    $formatHours = fn ($hours) => rtrim(rtrim(number_format((float) $hours, 2), '0'), '.');
@endphp

<div
    class="space-y-5"
    x-data="{
        dragAssignment: null,
        dragSourcePeriod: null,
        dropTargetPeriod: null,
        startAssignmentDrag(event, assignmentId, sourcePeriod) {
            this.clearDragState();
            this.dragAssignment = Number(assignmentId);
            this.dragSourcePeriod = sourcePeriod;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.clearData();
            event.dataTransfer.setData('text/plain', String(assignmentId));
            event.dataTransfer.setData('application/x-schedule-assignment-id', String(assignmentId));
            event.dataTransfer.setData('application/x-schedule-source-period', sourcePeriod);
        },
        activateDropPeriod(event, periodStartsOn) {
            if (!this.dragAssignment && !Array.from(event.dataTransfer.types).includes('text/plain')) {
                return;
            }

            event.dataTransfer.dropEffect = 'move';
            this.dropTargetPeriod = periodStartsOn;
        },
        dropAssignment(event, periodStartsOn, targetAssigneeType = null, targetAssigneeId = null) {
            const transferAssignmentId = Number(event.dataTransfer.getData('application/x-schedule-assignment-id') || event.dataTransfer.getData('text/plain'));
            const assignmentId = transferAssignmentId || this.dragAssignment;
            const sourcePeriod = event.dataTransfer.getData('application/x-schedule-source-period') || this.dragSourcePeriod || null;

            this.dragAssignment = null;
            this.dragSourcePeriod = null;
            this.dropTargetPeriod = null;

            if (!assignmentId) {
                return;
            }

            $wire.moveAssignmentToPeriod(Number(assignmentId), periodStartsOn, sourcePeriod, targetAssigneeType, targetAssigneeId);
        },
        clearDragState() {
            this.dragAssignment = null;
            this.dragSourcePeriod = null;
            this.dropTargetPeriod = null;
        },
    }"
>
    <div class="relative z-30 w-[calc(100vw-2rem)] left-1/2 -translate-x-1/2 mb-6 flex flex-col gap-3">
        <div class="grid gap-y-3 xl:grid-cols-[minmax(220px,auto)_1fr] xl:items-start xl:gap-x-3">
            <div class="flex flex-wrap items-center gap-3">
                @if($canEdit)
                    <div class="relative" x-data="{ addMenuOpen: false }" @click.outside="addMenuOpen = false">
                        <button type="button" @click="addMenuOpen = ! addMenuOpen" class="schedule-primary-button">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div
                            x-cloak
                            x-show="addMenuOpen"
                            x-transition.origin.top.left
                            class="absolute left-0 z-50 mt-2 w-44 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg"
                        >
                            <button type="button" wire:click="openAssignmentModal" @click="addMenuOpen = false" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">
                                Assignment
                            </button>
                            <button type="button" wire:click="openTimeOffModal" @click="addMenuOpen = false" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">
                                Time off
                            </button>
                            <button type="button" wire:click="openPlaceholderModal" @click="addMenuOpen = false" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">
                                Placeholder
                            </button>
                        </div>
                    </div>
                @endif

                <div class="schedule-segmented">
                    <button wire:click="setViewMode('team')" class="schedule-segment-button {{ $viewMode === 'team' ? 'schedule-segment-button-active' : '' }}">Team</button>
                    <button wire:click="setViewMode('projects')" class="schedule-segment-button {{ $viewMode === 'projects' ? 'schedule-segment-button-active' : '' }}">Projects</button>
                </div>
            </div>

            <div class="flex flex-col gap-3 xl:items-end">
                <div class="flex flex-wrap items-center gap-3 xl:justify-end">
                    <div class="schedule-segmented">
                        <button wire:click="previousPeriod" class="schedule-segment-icon" title="Previous">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button wire:click="setScale('day')" class="schedule-segment-button {{ $scale === 'day' ? 'schedule-segment-button-active' : '' }}">Day</button>
                        <button wire:click="setScale('week')" class="schedule-segment-button {{ $scale === 'week' ? 'schedule-segment-button-active' : '' }}">Week</button>
                        <button wire:click="setScale('month')" class="schedule-segment-button {{ $scale === 'month' ? 'schedule-segment-button-active' : '' }}">Month</button>
                        <button wire:click="nextPeriod" class="schedule-segment-icon" title="Next">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>

                    <div class="relative" x-data>
                        <button
                            type="button"
                            @click="$refs.scheduleDate.showPicker?.() ?? $refs.scheduleDate.click()"
                            title="Pick a date"
                            class="schedule-icon-button"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </button>
                        <input x-ref="scheduleDate" type="date" value="{{ $selectedDate }}" @change="$wire.selectDate($event.target.value)" class="absolute inset-0 opacity-0" style="pointer-events:none;">
                    </div>

                    <button wire:click="goToToday" class="schedule-secondary-button">Today</button>

                    <select wire:model.live="scheduleFilter" class="schedule-select w-full sm:w-56">
                        @if($viewMode === 'team')
                            <optgroup label="Display">
                                <option value="metric:availability">Availability</option>
                                <option value="metric:capacity">Scheduled capacity</option>
                            </optgroup>
                            <optgroup label="Roles">
                                @foreach($roleOptions as $role)
                                    <option value="role:{{ $role }}">{{ $role }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Teams">
                                @foreach($teamOptions as $team)
                                    <option value="team:{{ $team['id'] }}">{{ $team['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @else
                            <option value="metric:availability">Availability</option>
                        @endif
                        <optgroup label="Projects">
                            <option value="filter:all">All projects</option>
                            @foreach($allProjects as $project)
                                <option value="project:{{ $project->id }}">{{ $project->name }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>
            </div>
        </div>

        @if(session('schedule_status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('schedule_status') }}</div>
        @endif
    </div>

    <div class="w-[calc(100vw-2rem)] relative left-1/2 -translate-x-1/2 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div style="min-width: {{ 360 + ($periodCount * ($scale === 'month' ? 132 : 104)) }}px;">
            <div class="grid border-b border-gray-200 bg-gray-50 text-sm" style="grid-template-columns: {{ $gridTemplate }};">
                <div class="sticky left-0 z-20 flex items-center border-r border-gray-200 bg-gray-50 px-4 py-3 font-medium text-gray-600">
                    {{ $viewMode === 'team' ? 'People and placeholders' : 'Projects' }}
                </div>
                @foreach($periods as $period)
                    <div class="border-r border-gray-200 px-3 py-2 text-center {{ $period['is_current'] ? 'bg-blue-50' : '' }}">
                        <div class="text-xs text-gray-400">{{ $period['label'] }}</div>
                        <div class="font-medium {{ $period['is_today'] || $period['is_current'] ? 'text-blue-700' : 'text-gray-700' }}">{{ $period['sublabel'] }}</div>
                    </div>
                @endforeach
            </div>

            @if($viewMode === 'projects')
                <div class="grid border-b border-gray-200" style="grid-template-columns: {{ $gridTemplate }};">
                    <div class="sticky left-0 z-10 border-r border-gray-200 bg-white px-4 py-3">
                        <div class="font-semibold text-gray-900">Time Off</div>
                        <div class="text-xs text-gray-500">{{ count($timeOffRows) }} {{ \Illuminate\Support\Str::plural('entry', count($timeOffRows)) }}</div>
                    </div>
                    @foreach($periods as $period)
                        <div class="min-h-20 border-r border-gray-100 p-1.5 {{ $period['is_current'] ? 'bg-blue-50/50' : '' }}">
                            @foreach($timeOffRows as $entry)
                                @if(isset($entry['period_hours'][$period['index']]))
                                    <button wire:click="editTimeOff({{ $entry['id'] }})" class="mb-1 w-full rounded-md border border-gray-200 bg-gray-100 px-2 py-1 text-left text-xs text-gray-700 hover:bg-gray-200">
                                        {{ $entry['user_name'] }} · {{ $formatHours($entry['period_hours'][$period['index']]) }}h
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>

                @forelse($projectRows as $project)
                    <div class="grid border-b border-gray-200" style="grid-template-columns: {{ $gridTemplate }};">
                        <div class="sticky left-0 z-10 border-r border-gray-200 bg-white px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="h-3 w-3 rounded-full" style="background-color: {{ $project['colour'] }}"></span>
                                        <button wire:click="toggleProject({{ $project['id'] }})" class="truncate text-left font-semibold text-gray-900 hover:text-blue-700">
                                            {{ $project['name'] }}
                                        </button>
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-500">{{ $project['client_name'] }} · {{ $formatHours($project['scheduled_hours']) }}h scheduled</div>
                                </div>
                                <button wire:click="toggleProject({{ $project['id'] }})" class="text-gray-400 hover:text-gray-700" title="{{ $project['expanded'] ? 'Collapse' : 'Expand' }}">
                                    <svg class="h-4 w-4 {{ $project['expanded'] ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                            </div>
                            @if($canEdit)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button wire:click="openAssignmentModal({{ $project['id'] }})" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Assign</button>
                                    <button wire:click="openShiftTimeline({{ $project['id'] }})" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Shift</button>
                                </div>
                            @endif
                        </div>
                        @foreach($periods as $period)
                            <div
                                class="min-h-24 border-r border-gray-100 p-1.5 {{ $period['is_current'] ? 'bg-blue-50/50' : '' }}"
                                x-bind:class="dropTargetPeriod === '{{ $period['starts_on'] }}' ? 'ring-2 ring-inset ring-blue-400 bg-blue-50' : ''"
                                @if($canEdit)
                                    @dragenter.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                    @dragover.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                    @drop.prevent.stop="dropAssignment($event, '{{ $period['starts_on'] }}')"
                                @endif
                            >
                                @foreach($project['assignments'] as $assignment)
                                    @if(isset($assignment['period_hours'][$period['index']]))
                                        <button
                                            wire:key="project-assignment-{{ $assignment['id'] }}-period-{{ $period['index'] }}"
                                            wire:click="editAssignment({{ $assignment['id'] }})"
                                            @if($canEdit) draggable="true" @dragstart.stop="startAssignmentDrag($event, {{ $assignment['id'] }}, '{{ $period['starts_on'] }}')" @dragend="clearDragState()" @endif
                                            class="mb-1 w-full rounded-md px-2 py-1 text-left text-xs font-medium text-white shadow-sm {{ $canEdit ? 'cursor-grab active:cursor-grabbing' : '' }}"
                                            style="background-color: {{ $assignment['colour'] }}"
                                            title="{{ $assignment['assignee_name'] }} · {{ $assignment['starts_on'] }} to {{ $assignment['ends_on'] }}"
                                        >
                                            {{ $assignment['assignee_name'] }} · {{ $formatHours($assignment['period_hours'][$period['index']]) }}h
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    @if($project['expanded'])
                        @foreach($project['assignments'] as $assignment)
                            <div class="grid border-b border-gray-100 bg-gray-50/60" style="grid-template-columns: {{ $gridTemplate }};">
                                <div class="sticky left-0 z-10 border-r border-gray-200 bg-gray-50 py-2 pl-4 pr-8">
                                    <div class="text-sm font-medium text-gray-800">{{ $assignment['assignee_name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $assignment['starts_on'] }} to {{ $assignment['ends_on'] }} · {{ $formatHours($assignment['hours_per_day']) }}h/day</div>
                                </div>
                                @foreach($periods as $period)
                                    <div
                                        class="flex min-h-14 items-center border-r border-gray-100 p-1.5 {{ $period['is_current'] ? 'bg-blue-50/50' : '' }}"
                                        x-bind:class="dropTargetPeriod === '{{ $period['starts_on'] }}' ? 'ring-2 ring-inset ring-blue-400 bg-blue-50' : ''"
                                        @if($canEdit)
                                            @dragenter.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                            @dragover.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                            @drop.prevent.stop="dropAssignment($event, '{{ $period['starts_on'] }}')"
                                        @endif
                                    >
                                        @if(isset($assignment['period_hours'][$period['index']]))
                                            <button
                                                wire:key="project-expanded-assignment-{{ $assignment['period_assignment_ids'][$period['index']] ?? $assignment['id'] }}-period-{{ $period['index'] }}"
                                                wire:click="editAssignment({{ $assignment['period_assignment_ids'][$period['index']] ?? $assignment['id'] }})"
                                                @if($canEdit) draggable="true" @dragstart.stop="startAssignmentDrag($event, {{ $assignment['period_assignment_ids'][$period['index']] ?? $assignment['id'] }}, '{{ $period['starts_on'] }}')" @dragend="clearDragState()" @endif
                                                class="w-full rounded-md px-2 py-1 text-left text-xs font-medium text-white {{ $canEdit ? 'cursor-grab active:cursor-grabbing' : '' }}"
                                                style="background-color: {{ $assignment['colour'] }}"
                                            >
                                                {{ $formatHours($assignment['period_hours'][$period['index']]) }}h
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    @endif
                @empty
                    <div class="px-6 py-12 text-center text-sm text-gray-400">No projects match this schedule view.</div>
                @endforelse
            @else
                @forelse($teamRows as $row)
                    <div class="grid border-b border-gray-200" style="grid-template-columns: {{ $gridTemplate }};">
                        <div class="sticky left-0 z-10 border-r border-gray-200 bg-white px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <button wire:click="toggleAssignee('{{ $row['key'] }}')" class="truncate text-left font-semibold text-gray-900 hover:text-blue-700">
                                        {{ $row['name'] }}
                                    </button>
                                </div>
                                <button wire:click="toggleAssignee('{{ $row['key'] }}')" class="self-center text-gray-400 hover:text-gray-700">
                                    <svg class="h-4 w-4 {{ $row['expanded'] ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                            </div>
                            @if($canEdit)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button wire:click="openAssignmentModal(null, '{{ $row['type'] }}', {{ $row['id'] }})" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Assign</button>
                                    @if($row['type'] === 'user')
                                        <button wire:click="openTimeOffModal({{ $row['id'] }})" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Time off</button>
                                    @else
                                        <button wire:click="editPlaceholder({{ $row['id'] }})" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Edit</button>
                                        <button wire:click="deletePlaceholder({{ $row['id'] }})" wire:confirm="Archive this placeholder?" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Archive</button>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @foreach($periods as $period)
                            @php
                                $metric = $row['metrics'][$period['index']];
                                $primary = $heatmapMetric === 'capacity' ? $metric['scheduled'] : $metric['availability'];
                                $primaryLabel = $heatmapMetric === 'capacity'
                                    ? $formatHours($metric['scheduled']).'h'
                                    : ($metric['availability'] < 0 ? $formatHours(abs($metric['availability'])).'h over' : $formatHours($metric['availability']).'h open');
                            @endphp
                            <div
                                class="min-h-20 border-r border-gray-100 p-1.5 {{ $period['is_current'] ? 'bg-blue-50/50' : '' }}"
                                x-bind:class="dropTargetPeriod === '{{ $period['starts_on'] }}' ? 'ring-2 ring-inset ring-blue-400 bg-blue-50' : ''"
                                @if($canEdit)
                                    @dragenter.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                    @dragover.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                    @drop.prevent.stop="dropAssignment($event, '{{ $period['starts_on'] }}', '{{ $row['type'] }}', {{ $row['id'] }})"
                                @endif
                            >
                                <div class="h-full rounded-md border px-2 py-1.5 text-xs {{ $metric['class'] }}">
                                    <div class="font-semibold">{{ $primaryLabel }}</div>
                                    <div class="mt-0.5 opacity-75">{{ $metric['project_count'] }} {{ \Illuminate\Support\Str::plural('project', $metric['project_count']) }}</div>
                                    @if($metric['time_off'] > 0)
                                        <div class="mt-0.5 opacity-75">{{ $formatHours($metric['time_off']) }}h off</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($row['expanded'])
                        @foreach($row['assignments'] as $assignment)
                            <div class="grid border-b border-gray-100 bg-gray-50/60" style="grid-template-columns: {{ $gridTemplate }};">
                                <div class="sticky left-0 z-10 border-r border-gray-200 bg-gray-50 px-8 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="h-3 w-3 rounded-full" style="background-color: {{ $assignment['colour'] }}"></span>
                                        <div>
                                            <div class="text-sm font-medium text-gray-800">{{ $assignment['project_name'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $assignment['client_name'] }} · {{ $formatHours($assignment['hours_per_day']) }}h/day</div>
                                        </div>
                                    </div>
                                </div>
                                @foreach($periods as $period)
                                    <div
                                        class="flex min-h-14 items-center border-r border-gray-100 p-1.5 {{ $period['is_current'] ? 'bg-blue-50/50' : '' }}"
                                        x-bind:class="dropTargetPeriod === '{{ $period['starts_on'] }}' ? 'ring-2 ring-inset ring-blue-400 bg-blue-50' : ''"
                                        @if($canEdit)
                                            @dragenter.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                            @dragover.prevent.stop="activateDropPeriod($event, '{{ $period['starts_on'] }}')"
                                            @drop.prevent.stop="dropAssignment($event, '{{ $period['starts_on'] }}', '{{ $row['type'] }}', {{ $row['id'] }})"
                                        @endif
                                    >
                                        @if(isset($assignment['period_hours'][$period['index']]))
                                            <button
                                                wire:key="team-assignment-{{ $assignment['period_assignment_ids'][$period['index']] ?? $assignment['id'] }}-period-{{ $period['index'] }}"
                                                wire:click="editAssignment({{ $assignment['period_assignment_ids'][$period['index']] ?? $assignment['id'] }})"
                                                @if($canEdit) draggable="true" @dragstart.stop="startAssignmentDrag($event, {{ $assignment['period_assignment_ids'][$period['index']] ?? $assignment['id'] }}, '{{ $period['starts_on'] }}')" @dragend="clearDragState()" @endif
                                                class="w-full rounded-md px-2 py-1 text-left text-xs font-medium text-white {{ $canEdit ? 'cursor-grab active:cursor-grabbing' : '' }}"
                                                style="background-color: {{ $assignment['colour'] }}"
                                            >
                                                {{ $formatHours($assignment['period_hours'][$period['index']]) }}h
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach

                        @foreach($row['time_off'] as $entry)
                            <div class="grid border-b border-gray-100 bg-gray-50/60" style="grid-template-columns: {{ $gridTemplate }};">
                                <div class="sticky left-0 z-10 border-r border-gray-200 bg-gray-50 px-8 py-2">
                                    <div class="text-sm font-medium text-gray-800">{{ $entry['label'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $entry['starts_on'] }} to {{ $entry['ends_on'] }}</div>
                                </div>
                                @foreach($periods as $period)
                                    <div class="min-h-14 border-r border-gray-100 p-1.5 {{ $period['is_current'] ? 'bg-blue-50/50' : '' }}">
                                        @if(isset($entry['period_hours'][$period['index']]))
                                            <button wire:click="editTimeOff({{ $entry['id'] }})" class="w-full rounded-md border border-gray-200 bg-gray-100 px-2 py-1 text-left text-xs text-gray-700">
                                                {{ $formatHours($entry['period_hours'][$period['index']]) }}h off
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    @endif
                @empty
                    <div class="px-6 py-12 text-center text-sm text-gray-400">No people or placeholders match this schedule view.</div>
                @endforelse
            @endif
        </div>
    </div>

    @if($showAssignmentModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-20" wire:click="closeAssignmentModal" x-data @keydown.escape.window="$wire.closeAssignmentModal()">
            <div class="w-full max-w-xl rounded-xl bg-white p-6 shadow-2xl" @click.stop>
                <div class="mb-5 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">{{ $editingAssignmentId ? 'Edit assignment' : 'New assignment' }}</h2>
                    <button wire:click="closeAssignmentModal" class="text-xl leading-none text-gray-400 hover:text-gray-700">&times;</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Project</label>
                        <select wire:model="assignmentProjectId" class="schedule-modal-select">
                            <option value="">Select project...</option>
                            @foreach($allProjects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }} ({{ $project->client?->name }})</option>
                            @endforeach
                        </select>
                        @error('assignmentProjectId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Assignee</label>
                            <select wire:model.live="assignmentAssigneeType" class="schedule-modal-select">
                                <option value="user">Person</option>
                                <option value="placeholder">Placeholder</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">&nbsp;</label>
                            @if($assignmentAssigneeType === 'placeholder')
                                <select wire:model="assignmentPlaceholderId" class="schedule-modal-select">
                                    <option value="">Select placeholder...</option>
                                    @foreach($allPlaceholders as $placeholder)
                                        <option value="{{ $placeholder->id }}">{{ $placeholder->name }}</option>
                                    @endforeach
                                </select>
                                @error('assignmentPlaceholderId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            @else
                                <select wire:model="assignmentUserId" class="schedule-modal-select">
                                    <option value="">Select person...</option>
                                    @foreach($allUsers as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                                @error('assignmentUserId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            @endif
                        </div>
                    </div>

                    @if($assignmentAssigneeType === 'user')
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input wire:model="addUserToProjectTeam" type="checkbox" class="rounded">
                            Add to project team if needed
                        </label>
                        @error('addUserToProjectTeam')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @endif

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Starts</label>
                            <input wire:model="assignmentStartsOn" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            @error('assignmentStartsOn')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Ends</label>
                            <input wire:model="assignmentEndsOn" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            @error('assignmentEndsOn')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Hours/day</label>
                            <input wire:model="assignmentHoursPerDay" type="number" min="0.25" max="24" step="0.25" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            @error('assignmentHoursPerDay')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Notes</label>
                        <textarea wire:model="assignmentNotes" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <div>
                        @if($editingAssignmentId)
                            <button wire:click="deleteAssignment({{ $editingAssignmentId }})" wire:confirm="Delete this assignment?" class="text-sm text-red-600 hover:underline">Delete</button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="closeAssignmentModal" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button wire:click="saveAssignment" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save assignment</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($showTimeOffModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-20" wire:click="closeTimeOffModal" x-data @keydown.escape.window="$wire.closeTimeOffModal()">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl" @click.stop>
                <div class="mb-5 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">{{ $editingTimeOffId ? 'Edit time off' : 'New time off' }}</h2>
                    <button wire:click="closeTimeOffModal" class="text-xl leading-none text-gray-400 hover:text-gray-700">&times;</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Person</label>
                        <select wire:model="timeOffUserId" class="schedule-modal-select">
                            <option value="">Select person...</option>
                            @foreach($allUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </select>
                        @error('timeOffUserId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Starts</label>
                            <input wire:model="timeOffStartsOn" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Ends</label>
                            <input wire:model="timeOffEndsOn" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Hours/day</label>
                            <input wire:model="timeOffHoursPerDay" type="number" min="0.25" max="24" step="0.25" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Label</label>
                        <input wire:model="timeOffLabel" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Notes</label>
                        <textarea wire:model="timeOffNotes" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    @error('timeOffStartsOn')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('timeOffEndsOn')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('timeOffHoursPerDay')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('timeOffLabel')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <div>
                        @if($editingTimeOffId)
                            <button wire:click="deleteTimeOff({{ $editingTimeOffId }})" wire:confirm="Delete this time off?" class="text-sm text-red-600 hover:underline">Delete</button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="closeTimeOffModal" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button wire:click="saveTimeOff" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save time off</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($showPlaceholderModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-20" wire:click="closePlaceholderModal" x-data @keydown.escape.window="$wire.closePlaceholderModal()">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl" @click.stop>
                <div class="mb-5 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">{{ $editingPlaceholderId ? 'Edit placeholder' : 'New placeholder' }}</h2>
                    <button wire:click="closePlaceholderModal" class="text-xl leading-none text-gray-400 hover:text-gray-700">&times;</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Name</label>
                        <input wire:model="placeholderName" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        @error('placeholderName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Role/title</label>
                        <input wire:model="placeholderRoleTitle" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Capacity hrs/week</label>
                        <input wire:model="placeholderWeeklyCapacity" type="number" min="0" max="168" step="0.5" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        @error('placeholderWeeklyCapacity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-500">Work days</label>
                        <div class="flex overflow-hidden rounded-lg border border-gray-300">
                            @foreach($weekDays as $day)
                                <label class="flex flex-1 cursor-pointer items-center justify-center border-r border-gray-300 px-3 py-2 text-sm last:border-r-0">
                                    <input wire:model="placeholderWorkDays" value="{{ $day['value'] }}" type="checkbox" class="sr-only peer">
                                    <span class="text-gray-400 peer-checked:font-semibold peer-checked:text-blue-700">{{ $day['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('placeholderWorkDays')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button wire:click="closePlaceholderModal" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button wire:click="savePlaceholder" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save placeholder</button>
                </div>
            </div>
        </div>
    @endif

    @if($showShiftModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-20" wire:click="closeShiftModal" x-data @keydown.escape.window="$wire.closeShiftModal()">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl" @click.stop>
                <div class="mb-5 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">Shift project timeline</h2>
                    <button wire:click="closeShiftModal" class="text-xl leading-none text-gray-400 hover:text-gray-700">&times;</button>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Shift assignments from</label>
                        <input wire:model="shiftFromDate" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        @error('shiftFromDate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">To instead start on</label>
                        <input wire:model="shiftNewStartDate" type="date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        @error('shiftNewStartDate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button wire:click="closeShiftModal" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button wire:click="shiftTimeline" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Shift timeline</button>
                </div>
            </div>
        </div>
    @endif
</div>
