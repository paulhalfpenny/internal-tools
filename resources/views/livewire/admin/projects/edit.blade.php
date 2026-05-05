<div>
    <div class="mb-6">
        <a href="{{ route('admin.projects') }}" class="text-sm text-gray-500 hover:text-gray-700">← Projects</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-1">{{ $project->name }}</h1>
    </div>

    @if(session('status'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded">{{ session('status') }}</div>
    @endif

    <div class="grid grid-cols-3 gap-6">
        {{-- Main details --}}
        <div class="col-span-2 space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Project details</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                        <select wire:model="clientId" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                        <input wire:model="code" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        @error('code')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input wire:model="name" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Default rate (£/hr)</label>
                            <input wire:model="defaultRate" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Billable</label>
                            <select wire:model="isBillable" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                <option value="1">Billable</option>
                                <option value="0">Non-billable</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Starts on</label>
                            <input wire:model="startsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ends on</label>
                            <input wire:model="endsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Budget --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex items-start justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-700">Budget</h2>
                    @if($budgetStatus)
                        <x-budget-usage :status="$budgetStatus" />
                    @endif
                </div>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Budget type</label>
                            <select wire:model.live="budgetType" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                <option value="">No budget</option>
                                @foreach($budgetTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            @error('budgetType')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                {{ $budgetType === 'monthly_ci' ? 'Monthly budget (£/month)' : 'Total fee (£)' }}
                            </label>
                            <input wire:model="budgetAmount" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2" {{ $budgetType === '' ? 'disabled' : '' }}>
                            @error('budgetAmount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Budget hours (optional)</label>
                            <input wire:model="budgetHours" type="number" step="0.25" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2" {{ $budgetType === '' ? 'disabled' : '' }}>
                        </div>
                        @if($budgetType === 'monthly_ci')
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Budget starts on</label>
                                <input wire:model="budgetStartsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                @error('budgetStartsOn')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Tasks --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Tasks</h2>
                <div class="space-y-2">
                    @foreach($allTasks as $task)
                        @php $assigned = isset($taskAssignments[$task->id]); @endphp
                        <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0">
                            <input
                                type="checkbox"
                                id="task-{{ $task->id }}"
                                {{ $assigned ? 'checked' : '' }}
                                wire:click="toggleTask({{ $task->id }}, {{ $task->is_default_billable ? 'true' : 'false' }})"
                                class="rounded"
                            >
                            <label for="task-{{ $task->id }}" class="flex-1 text-sm cursor-pointer">
                                <span class="inline-block w-2.5 h-2.5 rounded-full mr-1.5" style="background-color: {{ $task->colour }}"></span>
                                {{ $task->name }}
                            </label>
                            @if($assigned)
                                <label class="flex items-center gap-1.5 text-xs text-gray-600">
                                    <input
                                        type="checkbox"
                                        wire:model="taskAssignments.{{ $task->id }}.is_billable"
                                        class="rounded"
                                    >
                                    Billable
                                </label>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Users --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Team members</h2>
                <div class="space-y-2">
                    @foreach($allUsers as $user)
                        @php $assigned = isset($userAssignments[$user->id]); @endphp
                        <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0">
                            <input
                                type="checkbox"
                                id="user-{{ $user->id }}"
                                {{ $assigned ? 'checked' : '' }}
                                wire:click="toggleUser({{ $user->id }})"
                                class="rounded"
                            >
                            <label for="user-{{ $user->id }}" class="flex-1 text-sm cursor-pointer">{{ $user->name }}</label>
                            @if($assigned)
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500">Rate override (£/hr)</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        wire:model="userAssignments.{{ $user->id }}.hourly_rate_override"
                                        placeholder="—"
                                        class="w-24 border border-gray-300 rounded text-sm px-3 py-2"
                                    >
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- JDW sidebar --}}
        <div class="space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">JDW export</h2>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                        <select wire:model="jdwCategory" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                            <option value="">None</option>
                            @foreach($jdwCategories as $cat)
                                <option value="{{ $cat->value }}">{{ ucfirst(str_replace('_', ' ', $cat->value)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Sort order</label>
                        <input wire:model="jdwSortOrder" type="number" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                        <input wire:model="jdwStatus" type="text" placeholder="e.g. Live" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Est. launch</label>
                        <input wire:model="jdwEstimatedLaunch" type="text" placeholder="e.g. Q2 2026, TBC" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                        <textarea wire:model="jdwDescription" rows="4" class="w-full border border-gray-300 rounded text-sm px-3 py-2"></textarea>
                    </div>
                </div>
            </div>

            <button wire:click="save" class="w-full px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">
                Save project
            </button>
        </div>
    </div>
</div>
