<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">API tokens</h1>
        <p class="text-sm text-gray-500 mt-1">
            Personal access tokens let external apps log time on your behalf — for example the Freshdesk
            widget that ships time entries straight from a support ticket.
        </p>
    </div>

    @if($justIssuedToken)
        <div class="mb-6 max-w-2xl bg-amber-50 border border-amber-200 rounded-lg p-5">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-amber-900">New token: {{ $justIssuedName }}</p>
                    <p class="text-xs text-amber-800 mt-1">Copy this now — it won't be shown again.</p>
                </div>
                <button wire:click="dismissJustIssued"
                        class="text-amber-700 hover:text-amber-900 text-sm">Done</button>
            </div>
            <div class="mt-3 flex items-center gap-2"
                 x-data="{ copied: false, copy() { navigator.clipboard.writeText('{{ $justIssuedToken }}'); this.copied = true; setTimeout(() => this.copied = false, 1500); } }">
                <code class="flex-1 px-3 py-2 bg-white border border-amber-300 rounded text-sm font-mono break-all select-all">{{ $justIssuedToken }}</code>
                <button @click="copy()"
                        class="px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded">
                    <span x-show="!copied">Copy</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl mb-6">
        <h2 class="text-sm font-semibold text-gray-900 mb-3">Generate a new token</h2>
        <div class="flex gap-2">
            <input type="text"
                   wire:model="newTokenName"
                   wire:keydown.enter="generate"
                   placeholder="e.g. Freshdesk widget"
                   class="flex-1 border border-gray-300 rounded text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
            <button wire:click="generate"
                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded">
                Generate
            </button>
        </div>
        @error('newTokenName')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
    </div>

    <div class="bg-white rounded-lg border border-gray-200 max-w-2xl">
        <div class="px-6 py-3 border-b border-gray-100">
            <h2 class="text-sm font-semibold text-gray-900">Existing tokens</h2>
        </div>
        @if($tokens->isEmpty())
            <p class="px-6 py-8 text-sm text-gray-400 text-center">No tokens yet.</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($tokens as $token)
                    <li class="px-6 py-4 flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">
                                {{ $token->name }}
                                @if($token->revoked_at)
                                    <span class="text-xs text-gray-400 ml-2">— revoked {{ $token->revoked_at->diffForHumans() }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Created {{ $token->created_at->diffForHumans() }} ·
                                {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                            </p>
                        </div>
                        @unless($token->revoked_at)
                            <button wire:click="revoke({{ $token->id }})"
                                    wire:confirm="Revoke this token? Anywhere using it will stop working immediately."
                                    class="text-sm text-red-600 hover:text-red-800">Revoke</button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
