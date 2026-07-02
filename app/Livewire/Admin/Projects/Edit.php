<?php

namespace App\Livewire\Admin\Projects;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\User;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaTaskRefreshDispatcher;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public Project $project;

    public int $clientId;

    public ?int $managerUserId = null;

    public ?string $code = null;

    public string $name;

    public string $isBillable = '1';

    public string $startsOn;

    public string $endsOn;

    public string $budgetType = '';

    public string $budgetAmount = '';

    public string $budgetHours = '';

    public string $budgetStartsOn = '';

    // Task assignments: ordered list of assigned task IDs
    /** @var array<int, int> */
    public array $taskAssignments = [];

    // User assignments: user_id => ['hourly_rate_override' => string]
    /** @var array<int, array{hourly_rate_override: string}> */
    public array $userAssignments = [];

    /** @var array<int, string> Asana board gids linked to this project */
    public array $asanaProjectGids = [];

    public string $pendingAsanaProjectGid = '';

    public bool $asanaTaskRequired = true;

    public bool $showClearBudgetModal = false;

    public function mount(Project $project): void
    {
        Gate::authorize('manage-projects');

        $this->project = $project->load(['tasks', 'users', 'asanaProjects']);
        $this->clientId = $project->client_id;
        $this->managerUserId = $project->manager_user_id;
        $this->code = $project->code;
        $this->name = $project->name;
        $this->isBillable = $project->is_billable ? '1' : '0';
        $this->startsOn = $project->starts_on?->toDateString() ?? '';
        $this->endsOn = $project->ends_on?->toDateString() ?? '';
        $this->budgetType = $project->budget_type?->value ?? '';
        $this->budgetAmount = $project->budget_amount !== null ? (string) $project->budget_amount : '';
        $this->budgetHours = $project->budget_hours !== null ? (string) $project->budget_hours : '';
        $this->budgetStartsOn = $project->budget_starts_on?->toDateString() ?? '';
        $this->asanaProjectGids = $project->asanaProjects->pluck('gid')->values()->all();
        $this->asanaTaskRequired = (bool) $project->asana_task_required;

        foreach ($project->tasks as $task) {
            $this->taskAssignments[$task->id] = $task->id;
        }

        foreach ($project->users as $user) {
            /** @var Pivot $pivot */
            $pivot = $user->getRelation('pivot');
            $this->userAssignments[$user->id] = [
                'hourly_rate_override' => $pivot->getAttribute('hourly_rate_override') !== null
                    ? (string) $pivot->getAttribute('hourly_rate_override')
                    : '',
            ];
        }
    }

    public function updatedBudgetType(): void
    {
        if ($this->budgetAmount !== '' || $this->budgetHours !== '' || $this->budgetStartsOn !== '') {
            $this->showClearBudgetModal = true;
        }
    }

    public function clearBudgetFields(): void
    {
        $this->budgetAmount = '';
        $this->budgetHours = '';
        $this->budgetStartsOn = '';
        $this->showClearBudgetModal = false;
    }

    public function keepBudgetFields(): void
    {
        $this->showClearBudgetModal = false;
    }

    public function toggleTask(int $taskId): void
    {
        if (isset($this->taskAssignments[$taskId])) {
            unset($this->taskAssignments[$taskId]);
        } else {
            $this->taskAssignments[$taskId] = $taskId;
        }
    }

    // --- Team membership ---

    // Add-user modal state
    public bool $showAddUserModal = false;

    /** @var array<int, int> queued user IDs awaiting modal Save */
    public array $pendingNewUserIds = [];

    public ?int $pendingNewUserDropdown = null;

    public function openAddUserModal(): void
    {
        $this->pendingNewUserIds = [];
        $this->pendingNewUserDropdown = null;
        $this->showAddUserModal = true;
    }

    public function closeAddUserModal(): void
    {
        $this->showAddUserModal = false;
        $this->pendingNewUserIds = [];
        $this->pendingNewUserDropdown = null;
    }

    public function queuePendingUser(): void
    {
        if ($this->pendingNewUserDropdown === null) {
            return;
        }
        $id = (int) $this->pendingNewUserDropdown;
        if (! in_array($id, $this->pendingNewUserIds, true) && ! isset($this->userAssignments[$id])) {
            $this->pendingNewUserIds[] = $id;
        }
        $this->pendingNewUserDropdown = null;
    }

    public function unqueuePendingUser(int $userId): void
    {
        $this->pendingNewUserIds = array_values(array_filter(
            $this->pendingNewUserIds,
            fn ($id) => $id !== $userId,
        ));
    }

    public function confirmAddUsers(): void
    {
        Gate::authorize('manage-projects');

        // Include the dropdown's current value if the admin hit Save without
        // pressing Add another first.
        if ($this->pendingNewUserDropdown !== null) {
            $this->queuePendingUser();
        }

        foreach ($this->pendingNewUserIds as $userId) {
            if (isset($this->userAssignments[$userId])) {
                continue;
            }
            $this->project->users()->syncWithoutDetaching([
                $userId => ['hourly_rate_override' => null, 'rate_id' => null],
            ]);
            $this->userAssignments[$userId] = ['hourly_rate_override' => ''];
        }

        $this->closeAddUserModal();
    }

    // Remove-user confirmation modal state
    public ?int $confirmRemoveUserId = null;

    public function openRemoveUserModal(int $userId): void
    {
        $this->confirmRemoveUserId = $userId;
    }

    public function closeRemoveUserModal(): void
    {
        $this->confirmRemoveUserId = null;
    }

    public function confirmRemoveUser(): void
    {
        Gate::authorize('manage-projects');

        if ($this->confirmRemoveUserId === null) {
            return;
        }
        $this->project->users()->detach($this->confirmRemoveUserId);
        unset($this->userAssignments[$this->confirmRemoveUserId]);
        $this->confirmRemoveUserId = null;
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('manage-projects');

        // Used by tests; UI flow goes through openRemoveUserModal → confirmRemoveUser.
        $this->project->users()->detach($userId);
        unset($this->userAssignments[$userId]);
    }

    public function save(AsanaService $asana): void
    {
        Gate::authorize('manage-projects');

        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'managerUserId' => 'nullable|exists:users,id',
            'code' => 'nullable|string|max:50|unique:projects,code,'.$this->project->id,
            'name' => 'required|string|max:255',
            'isBillable' => 'boolean',
            'startsOn' => 'nullable|date',
            'endsOn' => 'nullable|date',
            'budgetType' => 'nullable|in:fixed_fee,monthly_ci',
            'budgetAmount' => 'nullable|numeric|min:0|required_with:budgetType',
            'budgetHours' => 'nullable|numeric|min:0',
            'budgetStartsOn' => 'nullable|date|required_if:budgetType,monthly_ci',
            'asanaProjectGids' => 'array',
            'asanaProjectGids.*' => [
                'string',
                'distinct',
                'exists:asana_projects,gid',
            ],
        ]);

        $previousGids = $this->project->asanaProjects()->pluck('gid')->all();
        $previousUserIds = $this->project->users()->pluck('users.id')->all();
        $selectedGids = array_values(array_unique($this->asanaProjectGids));
        $addedGids = array_values(array_diff($selectedGids, $previousGids));
        $removedGids = array_values(array_diff($previousGids, $selectedGids));

        $this->project->update([
            'client_id' => $this->clientId,
            'manager_user_id' => $this->managerUserId,
            'code' => $this->code ?: null,
            'name' => $this->name,
            'is_billable' => $this->isBillable === '1',
            'starts_on' => $this->startsOn ?: null,
            'ends_on' => $this->endsOn ?: null,
            'budget_type' => $this->budgetType !== '' ? BudgetType::from($this->budgetType) : null,
            'budget_amount' => $this->budgetAmount !== '' ? (float) $this->budgetAmount : null,
            'budget_hours' => $this->budgetHours !== '' ? (float) $this->budgetHours : null,
            'budget_starts_on' => $this->budgetStartsOn !== '' ? $this->budgetStartsOn : null,
            'asana_task_required' => $this->asanaTaskRequired,
        ]);

        $authUser = $this->authUser();
        $customFieldFailures = false;

        foreach ($addedGids as $gid) {
            $cached = AsanaProject::find($gid);
            $workspaceGid = $cached?->workspace_gid;
            $customFieldGid = null;

            if ($workspaceGid !== null && $authUser->asanaConnected()) {
                try {
                    $customFieldGid = $asana->forUser($authUser)
                        ->ensureHoursCustomField($gid, $workspaceGid);
                } catch (\Throwable $e) {
                    AsanaSyncLog::error('asana.project_link.custom_field_failed', [
                        'asana_project_gid' => $gid,
                        'error' => $e->getMessage(),
                    ], $this->project);
                    $customFieldFailures = true;
                }
            }

            $this->project->asanaProjects()->attach($gid, [
                'asana_custom_field_gid' => $customFieldGid,
            ]);

            if ($authUser->asanaConnected()) {
                PullAsanaTasksJob::dispatch($gid, $authUser->id);
            }
        }

        if ($removedGids !== []) {
            // Detach unlinked boards. Cached AsanaTask rows remain so historic
            // time entries that reference them keep their task names visible.
            $this->project->asanaProjects()->detach($removedGids);
        }

        if ($customFieldFailures) {
            session()->flash('asana_warning', 'One or more Asana boards were linked, but the cumulative-hours custom field could not be set up. It will be retried on the first time entry sync.');
        }

        $this->project->load('client');

        // Sync tasks. Billability is stored on the project-task pivot, using
        // the task's Agency/JDW default for this project's client.
        $assignedIds = array_values(array_unique(array_map('intval', $this->taskAssignments)));
        $tasksById = Task::whereIn('id', $assignedIds)->get()->keyBy('id');
        $taskSync = [];
        foreach ($assignedIds as $taskId) {
            $task = $tasksById->get($taskId);
            $taskSync[$taskId] = ['is_billable' => $task?->defaultBillableForProject($this->project) ?? false];
        }
        $this->project->tasks()->sync($taskSync);

        // Sync users
        $userSync = [];
        foreach ($this->userAssignments as $userId => $data) {
            $override = $data['hourly_rate_override'] !== '' ? (float) $data['hourly_rate_override'] : null;
            $userSync[$userId] = [
                'hourly_rate_override' => $override,
                'rate_id' => null,
            ];
        }
        $this->project->users()->sync($userSync);

        $this->forgetProjectPickerCaches(array_merge($previousUserIds, array_keys($this->userAssignments)));

        session()->flash('status', 'Project saved.');
    }

    public function addAsanaBoard(): void
    {
        Gate::authorize('manage-projects');

        $gid = trim($this->pendingAsanaProjectGid);
        if ($gid === '') {
            return;
        }
        if (in_array($gid, $this->asanaProjectGids, true)) {
            $this->pendingAsanaProjectGid = '';

            return;
        }
        $this->asanaProjectGids[] = $gid;
        $this->pendingAsanaProjectGid = '';
    }

    public function removeAsanaBoard(string $gid): void
    {
        Gate::authorize('manage-projects');

        $this->asanaProjectGids = array_values(array_filter(
            $this->asanaProjectGids,
            fn (string $existing) => $existing !== $gid,
        ));
    }

    public function refreshAsanaTasks(AsanaTaskRefreshDispatcher $dispatcher): void
    {
        Gate::authorize('manage-projects');

        if ($dispatcher->dispatchForProject($this->project) > 0) {
            session()->flash('status', 'Refreshing Asana tasks in the background.');
        }
    }

    public function render(ProjectBudgetCalculator $budgetCalculator): View
    {
        $authUser = $this->authUser();
        $workspaceGid = $authUser->asana_workspace_gid;

        $asanaProjects = $workspaceGid !== null
            ? AsanaProject::query()
                ->where('workspace_gid', $workspaceGid)
                ->where('is_archived', false)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.admin.projects.edit', [
            'clients' => Client::where('is_archived', false)->orderBy('name')->get(),
            'allTasks' => Task::where('is_archived', false)->orderBy('sort_order')->orderBy('name')->get(),
            'allUsers' => User::where('is_active', true)->orderBy('name')->get(),
            'budgetTypes' => BudgetType::cases(),
            'budgetStatus' => $this->project->budget_type !== null ? $budgetCalculator->forProject($this->project) : null,
            'asanaProjects' => $asanaProjects,
            'asanaConnected' => $authUser->asanaConnected(),
            'rates' => Rate::where('is_archived', false)->orderBy('name')->get(),
        ]);
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @param  array<int, int|string>  $userIds
     */
    private function forgetProjectPickerCaches(array $userIds): void
    {
        collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->each(function (int $userId): void {
                Cache::forget("projects_picker_{$userId}");
                Cache::forget("projects_picker_eloquent_{$userId}");
            });
    }
}
