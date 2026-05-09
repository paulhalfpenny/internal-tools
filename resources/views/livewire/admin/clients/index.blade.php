<div x-data="{ showForm: false }">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Clients</h1>
        <div class="flex items-center gap-4">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name or code…"
                   class="w-64 border border-gray-300 rounded text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model.live="showArchived" type="checkbox" class="rounded"> Show archived
            </label>
            <button @click="showForm = true" x-show="!showForm"
                    class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                + New client
            </button>
        </div>
    </div>

    {{-- Create form --}}
    <div x-show="showForm" x-cloak class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-medium text-gray-700">Add client</h2>
            <button @click="showForm = false" class="text-sm text-gray-400 hover:text-gray-600">Cancel</button>
        </div>
        <div class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" placeholder="Client name" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="w-32">
                <label class="block text-xs font-medium text-gray-600 mb-1">Code</label>
                <input wire:model="code" type="text" placeholder="e.g. AAB" class="w-full border border-gray-300 rounded text-sm px-3 py-2 uppercase">
                @error('code')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <button wire:click="create" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Add</button>
        </div>
    </div>

    {{-- Clients table --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($clients as $client)
                    @if($editingId === $client->id)
                        <tr class="bg-blue-50">
                            <td class="px-4 py-2">
                                <input wire:model="editName" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                @error('editName')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editCode" type="text" class="w-28 border border-gray-300 rounded text-sm px-3 py-2 uppercase">
                                @error('editCode')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                            </td>
                            <td colspan="2" class="px-4 py-2">
                                <div class="flex gap-2">
                                    <button wire:click="save" class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                                    <button wire:click="cancel" class="px-3 py-2 bg-white border border-gray-300 text-sm rounded hover:bg-gray-50">Cancel</button>
                                </div>
                            </td>
                        </tr>
                        <tr class="bg-blue-50 border-t border-blue-100">
                            <td colspan="4" class="px-4 py-3">
                                <div class="text-xs font-semibold text-gray-700 mb-2">Default tasks for new projects under this client</div>
                                @if($allTasks->isEmpty())
                                    <p class="text-xs text-gray-400">No tasks defined yet.</p>
                                @else
                                    <div class="grid grid-cols-3 gap-x-4 gap-y-1">
                                        @foreach($allTasks as $task)
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" wire:model="editDefaultTaskIds" value="{{ $task->id }}" class="rounded">
                                                <span class="w-2.5 h-2.5 rounded-full" style="background: {{ $task->colour }}"></span>
                                                {{ $task->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @else
                        <tr class="{{ $client->is_archived ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3 font-medium">{{ $client->name }}</td>
                            <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $client->code ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($client->is_archived)
                                    <span class="text-xs text-gray-400">Archived</span>
                                @else
                                    <span class="text-xs text-green-600">Active</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <button wire:click="edit({{ $client->id }})" class="text-sm text-blue-600 hover:underline">Edit</button>
                                <button wire:click="toggleArchive({{ $client->id }})" class="text-sm text-gray-400 hover:text-gray-600 hover:underline">
                                    {{ $client->is_archived ? 'Unarchive' : 'Archive' }}
                                </button>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 text-sm">No clients yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
