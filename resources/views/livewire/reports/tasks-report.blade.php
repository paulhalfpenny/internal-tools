<div>
    @include('livewire.reports.partials.header', ['title' => 'Tasks', 'totals' => $totals])

    {{-- Stacked summary bar --}}
    @if($rows->isNotEmpty())
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide font-medium mb-3">Hours by task</p>
        <div class="flex h-5 rounded overflow-hidden gap-px">
            @foreach($rows->where('total_hours', '>', 0) as $row)
            @php $pct = $totals->totalHours > 0 ? $row->total_hours / $totals->totalHours * 100 : 0; @endphp
            <div class="h-full transition-all"
                 style="width: {{ $pct }}%; background: {{ $row->colour ?? '#3B82F6' }}"
                 title="{{ $row->label }}: {{ number_format($row->total_hours, 1) }}h">
            </div>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-3">
            @foreach($rows->where('total_hours', '>', 0) as $row)
            <span class="flex items-center gap-1.5 text-xs text-gray-600">
                <span class="inline-block w-2.5 h-2.5 rounded-sm flex-shrink-0"
                      style="background: {{ $row->colour ?? '#3B82F6' }}"></span>
                {{ $row->label }}
            </span>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200">
        @if($rows->isEmpty())
        <div class="py-16 text-center text-sm text-gray-400">No entries in this period.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="w-8 px-4 py-3"></th>
                    <th class="text-left px-4 py-3 font-medium">Task</th>
                    <th class="text-left px-4 py-3 font-medium w-48">Hours</th>
                    <th class="text-right px-4 py-3 font-medium">Total hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Billable hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php $barWidth = $maxHours > 0 ? ($row->total_hours / $maxHours * 100) : 0; @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <span class="inline-block w-3 h-3 rounded-full"
                              style="background: {{ $row->colour ?? '#3B82F6' }}"></span>
                    </td>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $row->label }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 h-2 bg-gray-100 rounded overflow-hidden">
                                <div class="h-full rounded"
                                     style="width: {{ $barWidth }}%; background: {{ $row->colour ?? '#3B82F6' }}">
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->total_hours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ number_format($row->billable_hours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->billable_amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-gray-200 bg-gray-50">
                <tr class="text-sm font-semibold text-gray-900">
                    <td class="px-4 py-3" colspan="3">Total</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals->totalHours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals->billableHours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($totals->billableAmount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
</div>
