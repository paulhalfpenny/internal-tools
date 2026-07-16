@use('App\Domain\TimeTracking\HoursFormatter')
<div>
    <div class="flex items-start justify-between mb-6">
        <div>
            <a href="{{ route('reports.projects') }}" class="text-sm text-gray-500 hover:text-gray-700">← Projects report</a>
            <h1 class="text-xl font-semibold text-gray-900 mt-1">{{ $project->name }}</h1>
            <p class="text-sm text-gray-500">{{ $project->client->name ?? '' }}</p>
        </div>
        <button wire:click="export"
                class="text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-2 hover:bg-gray-50 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Export CSV
        </button>
    </div>

    @if(! $status)
        <div class="bg-white rounded-lg border border-gray-200 p-6 text-sm text-gray-500">
            This project has no budget configured.
            <a href="{{ route('admin.projects.edit', $project) }}" class="text-blue-700 hover:underline">Set a budget</a>.
        </div>
    @else
        @php
            $pct = $status->percentUsed();
            $pctClass = $pct > 100 ? 'text-red-700'
                : ($pct >= 80 ? 'text-amber-700' : 'text-green-700');

            $currentMonthKey = \Carbon\CarbonImmutable::now()->format('Y-m');
            $currentMonthRow = $monthlyRows->first(fn ($r) => $r->month->format('Y-m') === $currentMonthKey);
            $thisMonthAmount = $currentMonthRow?->month_amount ?? 0.0;
            $thisMonthHours = $currentMonthRow?->month_hours ?? 0.0;
            $thisMonthBudget = $currentMonthRow?->month_budget ?? 0.0;
            $thisMonthPct = $thisMonthBudget > 0 ? round($thisMonthAmount / $thisMonthBudget * 100, 1) : null;
            $thisMonthClass = $thisMonthPct === null ? 'text-gray-700'
                : ($thisMonthPct > 100 ? 'text-red-700'
                : ($thisMonthPct >= 80 ? 'text-amber-700' : 'text-green-700'));
        @endphp

        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">This month</h2>
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">This month spent</div>
                <div class="text-base font-semibold text-gray-900 mt-1">£{{ number_format($thisMonthAmount, 2) }}</div>
                <div class="text-xs text-gray-500 mt-0.5">{{ HoursFormatter::format((float) $thisMonthHours, $hoursFormat) }} hrs</div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">This month budget</div>
                <div class="text-base font-semibold text-gray-900 mt-1">{{ $thisMonthBudget > 0 ? '£'.number_format($thisMonthBudget, 2) : '—' }}</div>
                <div class="text-xs text-gray-500 mt-0.5">
                    @if($status->budgetType->value === 'fixed_fee') no monthly target @else monthly @endif
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">This month %</div>
                <div class="text-base font-semibold {{ $thisMonthClass }} mt-1">{{ $thisMonthPct === null ? '—' : number_format($thisMonthPct, 1).'%' }}</div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4 opacity-0 pointer-events-none"></div>
        </div>

        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Lifetime / cumulative</h2>
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">Budget type</div>
                <div class="text-base font-semibold text-gray-900 mt-1">{{ $status->budgetType->label() }}</div>
                @if($status->budgetType->value === 'monthly_ci')
                    <div class="text-xs text-gray-500 mt-0.5">£{{ number_format((float) $project->budget_amount, 0) }}/mo from {{ optional($project->budget_starts_on)->format('M Y') }}</div>
                @endif
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">Cumulative budget</div>
                <div class="text-base font-semibold text-gray-900 mt-1">£{{ number_format($status->budgetAmount, 2) }}</div>
                @if($status->budgetHours !== null)
                    <div class="text-xs text-gray-500 mt-0.5">{{ HoursFormatter::format((float) $status->budgetHours, $hoursFormat) }} hrs target</div>
                @endif
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">Cumulative spent</div>
                <div class="text-base font-semibold text-gray-900 mt-1">£{{ number_format($status->actualAmount, 2) }}</div>
                <div class="text-xs text-gray-500 mt-0.5">{{ HoursFormatter::format((float) $status->actualHours, $hoursFormat) }} hrs</div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">Variance</div>
                <div class="text-base font-semibold {{ $pctClass }} mt-1">£{{ number_format($status->variance(), 2) }}</div>
                <div class="text-xs {{ $pctClass }} mt-0.5">{{ number_format($pct, 1) }}% used</div>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            @if($monthlyRows->isEmpty())
                <div class="py-12 text-center text-sm text-gray-400">No months to show yet.</div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                            <th class="text-left px-4 py-3 font-medium">Month</th>
                            <th class="text-right px-4 py-3 font-medium">Budget (£)</th>
                            <th class="text-right px-4 py-3 font-medium">Spent (£)</th>
                            <th class="text-right px-4 py-3 font-medium">Spent (hrs)</th>
                            <th class="text-right px-4 py-3 font-medium">Running budget</th>
                            <th class="text-right px-4 py-3 font-medium">Running spent</th>
                            <th class="text-right px-4 py-3 font-medium">Running variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($monthlyRows as $row)
                            @php
                                $varClass = $row->running_variance < 0 ? 'text-red-700' : 'text-gray-700';
                                $isCurrentMonth = $row->month->format('Y-m') === $currentMonthKey;
                                $rowClass = $isCurrentMonth ? 'bg-blue-50/50' : '';
                            @endphp
                            <tr class="hover:bg-gray-50 {{ $rowClass }}">
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ $row->month_label }}
                                    @if($isCurrentMonth)<span class="ml-2 text-xs text-blue-700">(current)</span>@endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $row->month_budget > 0 ? '£'.number_format($row->month_budget, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->month_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ HoursFormatter::format((float) $row->month_hours, $hoursFormat) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->running_budget, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->running_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums {{ $varClass }}">£{{ number_format($row->running_variance, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 mt-6">Time entries</h2>
        {{-- Entries filters --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4 mb-3">
            <div class="grid grid-cols-5 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Month</label>
                    <select wire:model.live="filterMonth" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                        <option value="">All months</option>
                        @foreach($monthOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                    <input wire:model.live="filterFrom" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input wire:model.live="filterTo" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Task</label>
                    <select wire:model.live="filterTaskId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                        <option value="">All tasks</option>
                        @foreach($taskOptions as $task)
                            <option value="{{ $task->id }}">{{ $task->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">User</label>
                    <select wire:model.live="filterUserId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                        <option value="">All users</option>
                        @foreach($userOptions as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if($hasFilters)
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                    <div class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">{{ $entries->total() }}</span> entries
                        · <span class="font-medium text-gray-900">{{ HoursFormatter::format((float) $filteredTotals->totalHours, $hoursFormat) }}</span> hrs
                        · <span class="font-medium text-gray-900">£{{ number_format($filteredTotals->billableAmount, 2) }}</span>
                    </div>
                    <button wire:click="clearFilters" class="text-sm text-gray-500 hover:text-gray-700">Clear filters</button>
                </div>
            @endif
        </div>
        <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            @if($entries->isEmpty())
                <div class="py-12 text-center text-sm text-gray-400">{{ $hasFilters ? 'No time entries match the current filters.' : 'No time entries in this window yet.' }}</div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                            <th class="text-left px-4 py-3 font-medium">Date</th>
                            <th class="text-left px-4 py-3 font-medium">User</th>
                            <th class="text-left px-4 py-3 font-medium">Task</th>
                            <th class="text-left px-4 py-3 font-medium">Asana task</th>
                            <th class="text-right px-4 py-3 font-medium">Hours</th>
                            <th class="text-left px-4 py-3 font-medium">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($entries as $entry)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 tabular-nums text-gray-500">{{ $entry->spent_on->format('d M Y') }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $entry->user?->name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $entry->task?->name }}</td>
                                <td class="px-4 py-3 text-gray-500 truncate max-w-xs">
                                    @if($entry->asanaTask)
                                        <a href="https://app.asana.com/0/0/{{ $entry->asana_task_gid }}/f"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-blue-700 hover:underline">{{ $entry->asanaTask->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ HoursFormatter::format((float) $entry->hours, $hoursFormat) }}</td>
                                <td class="px-4 py-3 text-gray-500 truncate max-w-xs">{{ $entry->notes }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
