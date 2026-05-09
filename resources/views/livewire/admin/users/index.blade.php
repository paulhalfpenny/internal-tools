<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Users</h1>
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name, email or title…"
               class="w-72 border border-gray-300 rounded text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
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
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 px-6 py-8 max-h-[90vh] overflow-y-auto" @click.stop>
                <div class="flex items-center justify-between mb-6">
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

                <div class="grid grid-cols-2 gap-3 mb-6">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Employment</label>
                        <select wire:model="editIsContractor" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                            <option value="0">Employee</option>
                            <option value="1">Contractor</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Status</label>
                        <label class="flex items-center gap-2.5 text-sm h-[38px] {{ $isSelfEdit ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer' }}">
                            <input
                                wire:model="editIsActive"
                                type="checkbox"
                                class="rounded"
                                @disabled($isSelfEdit)
                            > Active
                        </label>
                    </div>
                </div>
                @error('editIsActive')<p class="text-red-600 text-xs -mt-4 mb-3">{{ $message }}</p>@enderror

                <div class="border-t border-gray-100 pt-8 mt-2 mb-5">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4">Notifications & reporting line</h3>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Line manager</label>
                        <select wire:model="editReportsToUserId" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                            <option value="">— None —</option>
                            @foreach($managerCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ ucfirst($candidate->role->value) }})</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Determines who receives the Friday digest about this user's hours.</p>
                        @error('editReportsToUserId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Pause notifications until</label>
                        <input wire:model="editNotificationsPausedUntil" type="date" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        <p class="text-xs text-gray-500 mt-1">Use during holidays/long absences. Reminders resume the day <strong>after</strong> this date.</p>
                        @error('editNotificationsPausedUntil')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex gap-6 mb-4">
                        <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                            <input wire:model="editEmailNotificationsEnabled" type="checkbox" class="rounded"> Email reminders
                        </label>
                        <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                            <input wire:model="editSlackNotificationsEnabled" type="checkbox" class="rounded"> Slack DMs
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Slack user ID</label>
                        <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-500 font-mono">{{ $editSlackUserId ?: '— not resolved yet —' }}</div>
                        <p class="text-xs text-gray-500 mt-1">Resolved automatically by the nightly Slack sync.</p>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button wire:click="save" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">Save changes</button>
                    <button wire:click="cancel" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
