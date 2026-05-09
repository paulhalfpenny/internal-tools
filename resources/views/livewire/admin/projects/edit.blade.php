<div>
    <div class="mb-6">
        <a href="{{ route('admin.projects') }}" class="text-sm text-gray-500 hover:text-gray-700">← Projects</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-1">{{ $project->name }}</h1>
    </div>

    @if(session('status'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded">{{ session('status') }}</div>
    @endif
    @if(session('asana_warning'))
        <div class="mb-4 px-4 py-2 bg-yellow-50 border border-yellow-200 text-yellow-700 text-sm rounded">{{ session('asana_warning') }}</div>
    @endif

    <div class="grid grid-cols-3 gap-6">
        {{-- Main details --}}
        <div class="col-span-2 space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Project details</h2>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                            <select wire:model="clientId" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Project manager / Account lead</label>
                            <select wire:model="managerUserId" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                <option value="">— None —</option>
                                @foreach($allUsers as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Receives budget alerts alongside admins.</p>
                        </div>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Library rate</label>
                            <select wire:model="defaultRateId" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                <option value="">— None —</option>
                                @foreach($rates as $rate)
                                    <option value="{{ $rate->id }}">{{ $rate->label() }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">If set, takes precedence over the custom rate.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Custom rate (£/hr)</label>
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
                    </div>
                    <div class="grid grid-cols-4 gap-4 mt-4">
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

            {{-- Asana --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex items-start justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-700">Asana</h2>
                    @if($project->asana_project_gid)
                        <button type="button" wire:click="refreshAsanaTasks" class="px-3 py-1 text-xs font-medium text-blue-700 border border-blue-200 rounded hover:bg-blue-50">
                            Refresh tasks
                        </button>
                    @endif
                </div>

                @if(! $asanaConnected)
                    <p class="text-sm text-gray-500">
                        Connect your Asana account on
                        <a href="{{ route('profile.asana') }}" class="text-blue-700 underline">your profile</a>
                        to link projects.
                    </p>
                @elseif($asanaProjects->isEmpty())
                    <p class="text-sm text-gray-500">
                        No cached Asana projects yet. Visit
                        <a href="{{ route('admin.integrations.asana') }}" class="text-blue-700 underline">Asana integration</a>
                        and click "Pull projects from my workspace".
                    </p>
                @else
                    <label class="block text-sm font-medium text-gray-700 mb-1">Linked Asana project</label>
                    <select wire:model="asanaProjectGid" class="w-full border border-gray-300 rounded text-sm px-3 py-2" style="-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                        <option value="">Not linked</option>
                        @foreach($asanaProjects as $ap)
                            <option value="{{ $ap->gid }}">{{ $ap->name }}</option>
                        @endforeach
                    </select>
                    @error('asanaProjectGid')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    <p class="text-xs text-gray-500 mt-2">
                        When linked, time entries on this project must pick an Asana task. Cumulative hours are pushed to a custom field on each task.
                    </p>
                @endif
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
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-700">Team members &amp; rates</h2>
                    <a href="{{ route('admin.rates.library') }}" class="text-xs text-blue-600 hover:underline">Manage rate library →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                                <th class="px-2 py-2 w-8"></th>
                                <th class="text-left px-2 py-2 font-medium">Member</th>
                                <th class="text-left px-2 py-2 font-medium">Default role &amp; rate</th>
                                <th class="text-left px-2 py-2 font-medium">Project rate (£/hr)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                        @foreach($allUsers as $user)
                            @php
                                $assigned = isset($userAssignments[$user->id]);
                                $fallbackLabel = '£'.number_format(\App\Domain\Billing\RateResolver::FALLBACK_HOURLY_RATE, 2).'/hr';
                                if ($user->rate_id) {
                                    $libRate = $rates->firstWhere('id', $user->rate_id);
                                    $userDefault = $libRate
                                        ? $libRate->name.' — £'.number_format((float) $libRate->hourly_rate, 2).'/hr'
                                        : $fallbackLabel;
                                } else {
                                    $userDefault = $fallbackLabel;
                                }
                            @endphp
                            <tr>
                                <td class="px-2 py-2">
                                    <input type="checkbox" id="user-{{ $user->id }}" {{ $assigned ? 'checked' : '' }}
                                           wire:click="toggleUser({{ $user->id }})" class="rounded">
                                </td>
                                <td class="px-2 py-2">
                                    <label for="user-{{ $user->id }}" class="cursor-pointer">{{ $user->name }}</label>
                                </td>
                                <td class="px-2 py-2 text-gray-500">{{ $userDefault }}</td>
                                @if($assigned)
                                    <td class="px-2 py-2">
                                        <input type="number" step="0.01" min="0"
                                               wire:model="userAssignments.{{ $user->id }}.hourly_rate_override"
                                               placeholder="—"
                                               class="w-28 border border-gray-300 rounded text-sm px-2 py-1.5">
                                    </td>
                                @else
                                    <td class="px-2 py-2 text-xs text-gray-300">Not assigned</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
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
