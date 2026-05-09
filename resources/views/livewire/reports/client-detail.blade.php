<div>
    @include('livewire.reports.partials.header', [
        'title' => $client->name,
        'totals' => $totals,
        'backLink' => route('reports.clients'),
    ])

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        @if($rows->isEmpty())
        <div class="py-16 text-center text-sm text-gray-400">No entries for this client in this period.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="text-left px-4 py-3 font-medium">Project</th>
                    <th class="text-right px-4 py-3 font-medium">Hours</th>
                    <th class="text-right px-4 py-3 font-medium">Billable hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Amount</th>
                    <th class="text-right px-4 py-3 font-medium">Budget % used</th>
                    <th class="text-right px-4 py-3 font-medium"></th>
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
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-900">
                        @if($b)
                            <a href="{{ route('reports.projects.budget', $row->id) }}" class="text-blue-700 hover:underline">{{ $row->label }}</a>
                        @else
                            {{ $row->label }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->total_hours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ number_format($row->billable_hours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->billable_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums {{ $pctClass }}">{{ $pct === null ? '—' : number_format($pct, 1).'%' }}</td>
                    <td class="px-4 py-3 text-right">
                        <button wire:click="exportForProject({{ $row->id }})"
                                class="text-xs text-gray-500 hover:text-blue-600 hover:underline">
                            Export CSV
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-gray-200 bg-gray-50">
                <tr class="text-sm font-semibold text-gray-900">
                    <td class="px-4 py-3">Total</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals->totalHours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals->billableHours, 1) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($totals->billableAmount, 2) }}</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3"></td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
</div>
