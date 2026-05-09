<div x-data="{ showForm: false }">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Projects</h1>
        <div class="flex items-center gap-4">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name, code or client…"
                   class="w-72 border border-gray-300 rounded text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model.live="showArchived" type="checkbox" class="rounded"> Show archived
            </label>
            <button @click="showForm = true" x-show="!showForm"
                    class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                + New project
            </button>
        </div>
    </div>

    {{-- Create form --}}
    <div x-show="showForm" x-cloak class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-medium text-gray-700">New project</h2>
            <button @click="showForm = false" class="text-sm text-gray-400 hover:text-gray-600">Cancel</button>
        </div>
        <div class="grid grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Client <span class="text-red-500">*</span></label>
                <select wire:model="clientId" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                    <option value="">Select client…</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('clientId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Project name <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Project code <span class="text-red-500">*</span></label>
                <input wire:model="code" type="text" placeholder="e.g. AAB001" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('code')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="mt-4 grid grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Default rate (£/hr)</label>
                <input wire:model="defaultRate" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                @error('defaultRate')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Billable</label>
                <select wire:model="isBillable" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                    <option value="1">Billable</option>
                    <option value="0">Non-billable</option>
                </select>
            </div>
            <div></div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-100">
            <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-3">Budget (optional)</h3>
            <div class="grid grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Budget type</label>
                    <select wire:model.live="budgetType" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        <option value="">No budget</option>
                        @foreach($budgetTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        {{ $budgetType === 'monthly_ci' ? 'Monthly budget (£)' : 'Total fee (£)' }}
                    </label>
                    <input wire:model="budgetAmount" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2" {{ $budgetType === '' ? 'disabled' : '' }}>
                    @error('budgetAmount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Budget hours</label>
                    <input wire:model="budgetHours" type="number" step="0.25" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2" {{ $budgetType === '' ? 'disabled' : '' }}>
                </div>
                @if($budgetType === 'monthly_ci')
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Budget starts on</label>
                        <input wire:model="budgetStartsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        @error('budgetStartsOn')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                @else
                    <div></div>
                @endif
            </div>
        </div>

        <div class="mt-4 flex justify-start">
            <button wire:click="save" class="px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">
                Create project
            </button>
        </div>
    </div>

    {{-- Projects table --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Project</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Client</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Billable</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600">Rate</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($projects as $project)
                    <tr class="{{ $project->is_archived ? 'opacity-50' : '' }}">
                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $project->code }}</td>
                        <td class="px-4 py-3 font-medium">{{ $project->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $project->client->name }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $project->is_billable ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $project->is_billable ? 'Billable' : 'Non-billable' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700">
                            {{ $project->default_hourly_rate !== null ? '£'.number_format((float)$project->default_hourly_rate, 2) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right space-x-3">
                            <a href="{{ route('admin.projects.edit', $project) }}" class="text-sm text-blue-600 hover:underline">Edit</a>
                            <button wire:click="duplicate({{ $project->id }})"
                                    wire:confirm="Duplicate '{{ $project->name }}'? Tasks, users, rate and budget settings will be copied; time entries will not."
                                    class="text-sm text-gray-500 hover:text-gray-700 hover:underline">Duplicate</button>
                            <button wire:click="toggleArchive({{ $project->id }})" class="text-sm text-gray-400 hover:text-gray-600 hover:underline">
                                {{ $project->is_archived ? 'Unarchive' : 'Archive' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">No projects yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
