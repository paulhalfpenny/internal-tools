<div x-data="{ showForm: false }">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Teams</h1>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model.live="showArchived" type="checkbox" class="rounded"> Show archived
            </label>
            <button @click="showForm = true" x-show="!showForm"
                    class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                + New team
            </button>
        </div>
    </div>

    <div x-show="showForm" x-cloak class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-medium text-gray-700">Add team</h2>
            <button @click="showForm = false" class="text-sm text-gray-400 hover:text-gray-600">Cancel</button>
        </div>
        <div class="grid gap-3 md:grid-cols-[1fr_1.4fr_auto_auto] md:items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" placeholder="Development" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                <input wire:model="description" type="text" placeholder="Optional description" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Colour</label>
                <input wire:model="colour" type="color" class="h-9 w-16 border border-gray-300 rounded cursor-pointer p-0.5">
                @error('colour')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <button wire:click="create" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Add</button>
        </div>
        <div class="mt-4">
            <div class="mb-2 flex items-center justify-between gap-3">
                <label class="block text-xs font-medium text-gray-600">Members</label>
                <span class="text-xs text-gray-400">{{ $selectedUsers->count() }} selected</span>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                <div class="mb-3 flex flex-wrap gap-2">
                    @forelse($selectedUsers as $user)
                        <span class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700">
                            {{ $user->name }}
                            <button type="button" wire:click="removeUser({{ $user->id }})" class="text-gray-400 hover:text-gray-700" aria-label="Remove {{ $user->name }}">&times;</button>
                        </span>
                    @empty
                        <span class="text-sm text-gray-400">No members selected yet.</span>
                    @endforelse
                </div>
                <input
                    wire:model.live.debounce.200ms="userSearch"
                    type="search"
                    placeholder="Search people to add..."
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                <div class="mt-2 max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200 bg-white">
                    @forelse($availableUsers as $user)
                        <button
                            type="button"
                            wire:click="addUser({{ $user->id }})"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50"
                        >
                            <span>
                                <span class="block font-medium text-gray-800">{{ $user->name }}</span>
                                <span class="block text-xs text-gray-500">{{ $user->role_title ?: $user->email }}</span>
                            </span>
                            <span class="text-xs font-medium text-blue-600">Add</span>
                        </button>
                    @empty
                        <div class="px-3 py-4 text-sm text-gray-400">No matching active users.</div>
                    @endforelse
                </div>
            </div>
            @error('userIds')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            @error('userIds.*')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Team</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Description</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Members</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($teams as $team)
                    @if($editingId === $team->id)
                        <tr class="bg-blue-50">
                            <td class="px-4 py-2" colspan="4">
                                <div class="grid gap-3 md:grid-cols-[1fr_1.4fr_auto_auto] md:items-end">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                                        <input wire:model="editName" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                        @error('editName')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                                        <input wire:model="editDescription" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Colour</label>
                                        <input wire:model="editColour" type="color" class="h-9 w-16 border border-gray-300 rounded cursor-pointer p-0.5">
                                        @error('editColour')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="flex gap-2">
                                        <button wire:click="save" class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                                        <button wire:click="cancel" class="px-3 py-2 bg-white border border-gray-300 text-sm rounded hover:bg-gray-50">Cancel</button>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <label class="block text-xs font-medium text-gray-600">Members</label>
                                        <span class="text-xs text-gray-400">{{ $selectedEditUsers->count() }} selected</span>
                                    </div>
                                    <div class="rounded-lg border border-blue-100 bg-white p-3">
                                        <div class="mb-3 flex flex-wrap gap-2">
                                            @forelse($selectedEditUsers as $user)
                                                <span class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-700">
                                                    {{ $user->name }}
                                                    <button type="button" wire:click="removeEditUser({{ $user->id }})" class="text-gray-400 hover:text-gray-700" aria-label="Remove {{ $user->name }}">&times;</button>
                                                </span>
                                            @empty
                                                <span class="text-sm text-gray-400">No members selected yet.</span>
                                            @endforelse
                                        </div>
                                        <input
                                            wire:model.live.debounce.200ms="editUserSearch"
                                            type="search"
                                            placeholder="Search people to add..."
                                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                        <div class="mt-2 max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200">
                                            @forelse($availableEditUsers as $user)
                                                <button
                                                    type="button"
                                                    wire:click="addEditUser({{ $user->id }})"
                                                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-blue-50"
                                                >
                                                    <span>
                                                        <span class="block font-medium text-gray-800">{{ $user->name }}</span>
                                                        <span class="block text-xs text-gray-500">{{ $user->role_title ?: $user->email }}</span>
                                                    </span>
                                                    <span class="text-xs font-medium text-blue-600">Add</span>
                                                </button>
                                            @empty
                                                <div class="px-3 py-4 text-sm text-gray-400">No matching active users.</div>
                                            @endforelse
                                        </div>
                                    </div>
                                    @error('editUserIds')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                    @error('editUserIds.*')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="{{ $team->is_archived ? 'opacity-50 bg-gray-50' : '' }}">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full" style="background-color: {{ $team->colour }}"></span>
                                    <span class="font-medium text-gray-900">{{ $team->name }}</span>
                                    @if($team->is_archived)
                                        <span class="text-xs text-gray-400">(archived)</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $team->description ?: '—' }}</td>
                            <td class="px-4 py-3 text-center text-gray-600">
                                <div class="font-medium">{{ $team->users_count }} {{ \Illuminate\Support\Str::plural('member', $team->users_count) }}</div>
                                @if($team->users->isNotEmpty())
                                    <div class="mx-auto mt-1 max-w-xs truncate text-xs text-gray-400">
                                        {{ $team->users->take(3)->pluck('name')->join(', ') }}@if($team->users_count > 3), +{{ $team->users_count - 3 }} more @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <button wire:click="edit({{ $team->id }})" class="text-sm text-blue-600 hover:underline">Edit</button>
                                <button wire:click="toggleArchive({{ $team->id }})" class="text-sm text-gray-500 hover:underline">
                                    {{ $team->is_archived ? 'Unarchive' : 'Archive' }}
                                </button>
                                <button wire:click="delete({{ $team->id }})" wire:confirm="Delete this team? Users will be detached from it." class="text-sm text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 text-sm">No teams yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
