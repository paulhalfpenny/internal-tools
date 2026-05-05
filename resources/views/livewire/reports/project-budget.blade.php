<div>
    <div class="mb-6">
        <a href="{{ route('reports.projects') }}" class="text-sm text-gray-500 hover:text-gray-700">← Projects report</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-1">{{ $project->name }}</h1>
        <p class="text-sm text-gray-500">{{ $project->client->name ?? '' }}</p>
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
                <div class="text-xs text-gray-500 mt-0.5">{{ number_format($thisMonthHours, 1) }} hrs</div>
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
                    <div class="text-xs text-gray-500 mt-0.5">{{ number_format($status->budgetHours, 1) }} hrs target</div>
                @endif
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-500">Cumulative spent</div>
                <div class="text-base font-semibold text-gray-900 mt-1">£{{ number_format($status->actualAmount, 2) }}</div>
                <div class="text-xs text-gray-500 mt-0.5">{{ number_format($status->actualHours, 1) }} hrs</div>
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
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ number_format($row->month_hours, 1) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->running_budget, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->running_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums {{ $varClass }}">£{{ number_format($row->running_variance, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</div>
