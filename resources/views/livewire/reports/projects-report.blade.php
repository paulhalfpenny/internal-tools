<div>
    @include('livewire.reports.partials.header', ['title' => 'Projects', 'totals' => $totals])

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        @if($rows->isEmpty())
        <div class="py-16 text-center text-sm text-gray-400">No entries in this period.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="px-4 py-2" colspan="2"></th>
                    <th class="px-4 py-2 text-center font-semibold text-gray-600 border-l border-gray-100" colspan="2">This period</th>
                    <th class="px-4 py-2 text-center font-semibold text-gray-600 border-l border-gray-100" colspan="5">Lifetime / cumulative</th>
                </tr>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="text-left px-4 py-3 font-medium">Client</th>
                    <th class="text-left px-4 py-3 font-medium">Project</th>
                    <th class="text-right px-4 py-3 font-medium border-l border-gray-100">Hours</th>
                    <th class="text-right px-4 py-3 font-medium">Spent (£)</th>
                    <th class="text-right px-4 py-3 font-medium border-l border-gray-100">Budget (£)</th>
                    <th class="text-right px-4 py-3 font-medium">Spent (£)</th>
                    <th class="text-right px-4 py-3 font-medium">Budget (hrs)</th>
                    <th class="text-right px-4 py-3 font-medium">Spent (hrs)</th>
                    <th class="text-right px-4 py-3 font-medium">% used</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                    $b = $row->budget_status ?? null;
                    $pct = $b ? $b->percentUsed() : null;
                    $pctClass = $pct === null ? 'text-gray-300'
                        : ($pct > 100 ? 'text-red-700 font-semibold'
                        : ($pct >= 80 ? 'text-amber-700 font-semibold' : 'text-gray-700'));
                    $tooltip = $b
                        ? ($b->budgetType->value === 'fixed_fee'
                            ? 'Lifetime fixed-fee budget'
                            : 'CI Retainer monthly budget × elapsed months')
                        : '';
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500">{{ $row->client_name }}</td>
                    <td class="px-4 py-3 font-medium text-gray-900">
                        @if($b)
                            <a href="{{ route('reports.projects.budget', $row->id) }}" class="text-blue-700 hover:underline">{{ $row->label }}</a>
                        @else
                            {{ $row->label }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums border-l border-gray-100">{{ number_format($row->total_hours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->billable_amount, 0) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums border-l border-gray-100" title="{{ $tooltip }}">{{ $b ? '£'.number_format($b->budgetAmount, 0) : '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $b ? '£'.number_format($b->actualAmount, 0) : '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $b && $b->budgetHours !== null ? number_format($b->budgetHours, 1) : '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $b ? number_format($b->actualHours, 1) : '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums {{ $pctClass }}">{{ $pct === null ? '—' : number_format($pct, 1).'%' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-gray-200 bg-gray-50">
                <tr class="text-sm font-semibold text-gray-900">
                    <td class="px-4 py-3" colspan="2">Total</td>
                    <td class="px-4 py-3 text-right tabular-nums border-l border-gray-100">{{ number_format($totals->totalHours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($totals->billableAmount, 0) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums border-l border-gray-100" colspan="5"></td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
</div>
