<x-app-layout>
    <div class="max-w-xl mx-auto">
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">OAuth access request</p>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ $client->name }} wants to access Internal Tools</h1>
                <p class="mt-2 text-sm text-gray-600">
                    You are signed in as {{ $user->email }}.
                </p>
            </div>

            <div class="border border-gray-200 rounded-md p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-900">Requested access</h2>
                <ul class="mt-3 space-y-2">
                    @forelse($scopes as $scope)
                        <li class="flex items-start gap-2 text-sm text-gray-700">
                            <span class="mt-[0.4375rem] h-1.5 w-1.5 rounded-full bg-blue-600"></span>
                            <span>{{ $scope->description }}</span>
                        </li>
                    @empty
                        <li class="text-sm text-gray-700">Basic account access</li>
                    @endforelse
                </ul>
            </div>

            <div class="flex items-center justify-end gap-3">
                <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Deny
                    </button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex items-center rounded-md border border-blue-700 bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
                        Approve
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
