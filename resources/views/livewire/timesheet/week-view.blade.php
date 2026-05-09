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

    {{-- Add row modal --}}
    @if($showAddRowModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
             wire:click="closeAddRowModal"
             x-data
             @keydown.escape.window="$wire.closeAddRowModal()">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 px-8 py-8" @click.stop>
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-base font-semibold text-gray-900">Add row to this timesheet</h2>
                    <button wire:click="closeAddRowModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Project</label>
                    <select wire:model.live="newRowProjectId" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        <option value="">— Select a project —</option>
                        @foreach($projectsForPicker as $project)
                            <option value="{{ $project['id'] }}">
                                {{ $project['client_name'] ? $project['client_name'].' — ' : '' }}{{ $project['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-6">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Task</label>
                    <select wire:model.live="newRowTaskId" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2"
                            @if(! $newRowProjectId) disabled @endif>
                        <option value="">— Select a task —</option>
                        @if($newRowProjectId)
                            @php $selectedProject = collect($projectsForPicker)->firstWhere('id', (int) $newRowProjectId); @endphp
                            @if($selectedProject)
                                @foreach($selectedProject['tasks'] as $task)
                                    <option value="{{ $task['id'] }}">{{ $task['name'] }}</option>
                                @endforeach
                            @endif
                        @endif
                    </select>
                </div>

                <div class="flex justify-end gap-2">
                    <button wire:click="closeAddRowModal"
                            class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                    <button wire:click="addRow"
                            @if(! $newRowProjectId || ! $newRowTaskId) disabled @endif
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md disabled:bg-gray-300 disabled:cursor-not-allowed">
                        Save row
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
