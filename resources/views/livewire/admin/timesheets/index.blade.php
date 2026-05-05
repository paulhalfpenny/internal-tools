<div>
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Employee timesheets</h1>
            <p class="text-sm text-gray-500 mt-1">Click an employee to view and edit their timesheet.</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="thisWeek"
                class="mr-2 inline-flex items-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-1.5 rounded-lg transition {{ $isCurrentWeek ? 'invisible' : '' }}">
                This week
            </button>
            <button wire:click="previousWeek"
                class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800"
                title="Previous week">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <div class="text-sm font-medium text-gray-800 px-3 py-1.5 min-w-[160px] text-center">
                {{ $weekStartDate->format('j M') }} – {{ $weekEndDate->format('j M Y') }}
            </div>
            <button wire:click="nextWeek"
                class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800"
                title="Next week">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600">Hours this week</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Last entry</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $row->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $row->email }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->week_hours, 1) }}</td>
                        <td class="px-4 py-3 text-gray-500">
                            {{ $row->last_entry ? \Carbon\Carbon::parse($row->last_entry)->format('j M Y') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.timesheets.user', $row->id) }}"
                               class="text-sm text-blue-600 hover:underline">Open timesheet →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 text-sm">No active employees.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
