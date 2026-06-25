<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            MCP action approval
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-5">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Requested action</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ str_replace('_', ' ', $pendingAction->action) }}</p>
                    </div>

                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ ucfirst(str_replace('_', ' ', $pendingAction->status)) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Expires</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $pendingAction->expires_at?->toDayDateTimeString() ?? 'No expiry' }}</dd>
                        </div>
                    </dl>

                    <div>
                        <p class="text-sm font-medium text-gray-500">Payload</p>
                        <pre class="mt-2 overflow-x-auto rounded-md bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($pendingAction->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>

                    @if ($pendingAction->status === 'pending')
                        <div class="flex gap-3">
                            <form method="POST" action="{{ route('mcp.pending-actions.approve', $pendingAction->approval_token) }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                    Approve and execute
                                </button>
                            </form>

                            <form method="POST" action="{{ route('mcp.pending-actions.reject', $pendingAction->approval_token) }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-200">
                                    Reject
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
