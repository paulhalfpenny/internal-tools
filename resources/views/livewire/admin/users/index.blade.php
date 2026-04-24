<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Users</h1>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Role</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Active</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($users as $user)
                    <tr class="{{ $user->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $user->role->value === 'admin' ? 'bg-red-100 text-red-700' : ($user->role->value === 'manager' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ ucfirst($user->role->value) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($user->is_active)
                                <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                            @else
                                <span class="inline-block w-2 h-2 rounded-full bg-gray-300"></span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $user->id }})" class="text-sm text-blue-600 hover:underline">Edit</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($editingId !== null)
        @php $isSelfEdit = $editingId === auth()->id(); @endphp
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
            wire:click="cancel"
            x-data
            @keydown.escape.window="$wire.cancel()"
        >
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6" @click.stop>
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-base font-semibold text-gray-900">Edit User</h2>
                    <button wire:click="cancel" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Name</label>
                    <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ $editName }}</div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Email</label>
                    <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-500">{{ $editEmail }}</div>
                </div>

                <div class="mb-1">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Role</label>
                    <select
                        wire:model.live="editRole"
                        class="w-full border border-gray-300 rounded-md text-sm px-3 py-2 {{ $isSelfEdit ? 'opacity-50 cursor-not-allowed bg-gray-50' : '' }}"
                        @disabled($isSelfEdit)
                    >
                        @foreach($roles as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-gray-500 mb-1 pl-0.5">
                    @if($editRole === 'admin')
                        Full access: timesheet, reports, and admin screens.
                    @elseif($editRole === 'manager')
                        Can view reports. Cannot access admin screens.
                    @else
                        Timesheet access only.
                    @endif
                </p>
                @error('editRole')
                    <p class="text-red-600 text-xs mb-1">{{ $message }}</p>
                @enderror
                @if($isSelfEdit)
                    <p class="text-xs text-amber-600 mt-1 mb-4">You cannot change your own role or deactivate yourself.</p>
                @endif
                <div class="mb-4"></div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Job Title</label>
                    <input wire:model="editRoleTitle" type="text" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Rate (£/hr)</label>
                        <input wire:model="editDefaultRate" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        @error('editDefaultRate')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Capacity (hrs/week)</label>
                        <input wire:model="editWeeklyCapacity" type="number" step="0.5" min="0" max="168" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        @error('editWeeklyCapacity')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="flex gap-5 mb-6">
                    <label class="flex items-center gap-2 text-sm {{ $isSelfEdit ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer' }}">
                        <input
                            wire:model="editIsActive"
                            type="checkbox"
                            class="rounded"
                            @disabled($isSelfEdit)
                        > Active
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="editIsContractor" type="checkbox" class="rounded"> Contractor
                    </label>
                </div>
                @error('editIsActive')<p class="text-red-600 text-xs -mt-4 mb-3">{{ $message }}</p>@enderror

                <div class="flex gap-2">
                    <button wire:click="save" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">Save changes</button>
                    <button wire:click="cancel" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
