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

            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                    {{ $errors->first() }}
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

                    @php
                        $formatValue = static function (mixed $value): string {
                            if (is_bool($value)) {
                                return $value ? 'Yes' : 'No';
                            }

                            if ($value === null || $value === '') {
                                return 'None';
                            }

                            if (is_array($value)) {
                                return json_encode($value, JSON_UNESCAPED_SLASHES);
                            }

                            return (string) $value;
                        };
                    @endphp

                    @if (! empty($details['subject']))
                        <div>
                            <p class="text-sm font-medium text-gray-500">Target record</p>
                            <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($details['subject'] as $key => $value)
                                    <div class="rounded-md border border-gray-200 p-3">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ \Illuminate\Support\Str::headline($key) }}</dt>
                                        <dd class="mt-1 break-words text-sm text-gray-900">{{ $formatValue($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    @if (! empty($details['requested_changes']))
                        <div>
                            <p class="text-sm font-medium text-gray-500">Requested changes</p>
                            <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($details['requested_changes'] as $key => $value)
                                    <div class="rounded-md border border-gray-200 p-3">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ \Illuminate\Support\Str::headline($key) }}</dt>
                                        <dd class="mt-1 break-words text-sm text-gray-900">{{ $formatValue($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif

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
