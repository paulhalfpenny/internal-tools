<div>
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Effective rates</h1>
            <p class="text-sm text-gray-500 mt-1">Read-only view of the resolved billable rate per assigned (project, user) combination.</p>
        </div>
        <a href="{{ route('admin.rates.library') }}" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
            Manage rate library →
        </a>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Project</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Client</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600 w-48">Team member</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600 w-40">Effective rate</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Source</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rates as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['project'] }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $row['client'] }}</td>
                        <td class="px-4 py-3">{{ $row['user'] }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            @if($row['effective_rate'] !== null)
                                £{{ number_format($row['effective_rate'], 2) }}
                            @else
                                <span class="text-gray-400">No rate</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs">{{ $row['source'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 text-sm">No assigned users on active projects yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
