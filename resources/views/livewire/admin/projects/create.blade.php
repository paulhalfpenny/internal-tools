<div>
    <div class="mb-6">
        <a href="{{ route('admin.projects') }}" class="text-sm text-gray-500 hover:text-gray-700">← Projects</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-1">New project</h1>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-lg">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client <span class="text-red-500">*</span></label>
                <select wire:model="clientId" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                    <option value="">Select client…</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('clientId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Project code <span class="text-red-500">*</span></label>
                <input wire:model="code" type="text" placeholder="e.g. AAB001" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('code')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Project name <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Default rate (£/hr)</label>
                    <input wire:model="defaultRate" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                    @error('defaultRate')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billable</label>
                    <select wire:model="isBillable" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                        <option value="1">Billable</option>
                        <option value="0">Non-billable</option>
                    </select>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-4">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Budget (optional)</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Budget type</label>
                        <select wire:model.live="budgetType" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                            <option value="">No budget</option>
                            @foreach($budgetTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ $budgetType === 'monthly_ci' ? 'Monthly budget (£)' : 'Total fee (£)' }}
                        </label>
                        <input wire:model="budgetAmount" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        @error('budgetAmount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                @if($budgetType !== '')
                    <div class="grid grid-cols-2 gap-4 mt-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Budget hours (optional)</label>
                            <input wire:model="budgetHours" type="number" step="0.25" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                        @if($budgetType === 'monthly_ci')
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Budget starts on</label>
                                <input wire:model="budgetStartsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                @error('budgetStartsOn')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="pt-2">
                <button wire:click="save" class="px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">
                    Create project
                </button>
            </div>
        </div>
    </div>
</div>
