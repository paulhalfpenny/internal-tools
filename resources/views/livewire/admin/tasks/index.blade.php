<div x-data="{ showForm: false }">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Tasks</h1>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model.live="showArchived" type="checkbox" class="rounded"> Show archived
            </label>
            <button @click="showForm = true" x-show="!showForm"
                    class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                + New task
            </button>
        </div>
    </div>

    {{-- Create form --}}
    <div x-show="showForm" x-cloak class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-medium text-gray-700">Add task</h2>
            <button @click="showForm = false" class="text-sm text-gray-400 hover:text-gray-600">Cancel</button>
        </div>
        <div class="flex gap-3 items-end flex-wrap">
            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" placeholder="Task name" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Colour</label>
                <input wire:model="colour" type="color" class="h-9 w-16 border border-gray-300 rounded cursor-pointer p-0.5">
            </div>
            <div class="flex items-center gap-4 pb-1">
                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                    <input wire:model="isDefaultBillable" type="checkbox" class="rounded">
                    Agency billable
                </label>
                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                    <input wire:model="isJdwDefaultBillable" type="checkbox" class="rounded">
                    JDW billable
                </label>
            </div>
            <button wire:click="create" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Add</button>
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600 w-8">Order</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Agency</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">JDW</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Colour</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($tasks as $task)
                    @if($editingId === $task->id)
                        <tr class="bg-blue-50">
                            <td class="px-4 py-2" colspan="6">
                                <div class="flex gap-3 items-end flex-wrap">
                                    <div class="flex-1 min-w-48">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                                        <input wire:model="editName" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                        @error('editName')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Colour</label>
                                        <input wire:model="editColour" type="color" class="h-9 w-16 border border-gray-300 rounded cursor-pointer p-0.5">
                                    </div>
                                    <div class="flex items-center gap-4 pb-1">
                                        <label class="flex items-center gap-1.5 text-sm">
                                            <input wire:model="editIsDefaultBillable" type="checkbox" class="rounded">
                                            Agency billable
                                        </label>
                                        <label class="flex items-center gap-1.5 text-sm">
                                            <input wire:model="editIsJdwDefaultBillable" type="checkbox" class="rounded">
                                            JDW billable
                                        </label>
                                    </div>
                                    <div class="flex gap-2 pb-1">
                                        <button wire:click="save" class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                                        <button wire:click="cancel" class="px-3 py-2 bg-white border border-gray-300 text-sm rounded hover:bg-gray-50">Cancel</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="{{ $task->is_archived ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-0.5">
                                    <button wire:click="moveUp({{ $task->id }})" class="text-gray-300 hover:text-gray-600 leading-none text-xs">▲</button>
                                    <button wire:click="moveDown({{ $task->id }})" class="text-gray-300 hover:text-gray-600 leading-none text-xs">▼</button>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-medium">{{ $task->name }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($task->is_default_billable)
                                    <span class="text-green-600 text-xs">Yes</span>
                                @else
                                    <span class="text-gray-400 text-xs">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($task->is_jdw_default_billable)
                                    <span class="text-green-600 text-xs">Yes</span>
                                @else
                                    <span class="text-gray-400 text-xs">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-block w-5 h-5 rounded-full border border-gray-200" style="background-color: {{ $task->colour }}"></span>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <button wire:click="edit({{ $task->id }})" class="text-sm text-blue-600 hover:underline">Edit</button>
                                <button wire:click="toggleArchive({{ $task->id }})" class="text-sm text-gray-400 hover:text-gray-600 hover:underline">
                                    {{ $task->is_archived ? 'Unarchive' : 'Archive' }}
                                </button>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">No tasks yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
