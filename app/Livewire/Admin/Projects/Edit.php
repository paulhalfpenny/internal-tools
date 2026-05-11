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
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

    public bool $isBillable = true;

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

    public string $asanaProjectGid = '';

    public function mount(Project $project): void
    {
        Gate::authorize('access-admin');

        $this->project = $project->load(['tasks', 'users']);
        $this->clientId = $project->client_id;
        $this->managerUserId = $project->manager_user_id;
        $this->code = $project->code;
        $this->name = $project->name;
        $this->isBillable = (bool) $project->is_billable;
        $this->startsOn = $project->starts_on?->toDateString() ?? '';
        $this->endsOn = $project->ends_on?->toDateString() ?? '';
        $this->budgetType = $project->budget_type?->value ?? '';
        $this->budgetAmount = $project->budget_amount !== null ? (string) $project->budget_amount : '';
        $this->budgetHours = $project->budget_hours !== null ? (string) $project->budget_hours : '';
        $this->budgetStartsOn = $project->budget_starts_on?->toDateString() ?? '';
        $this->asanaProjectGid = $project->asana_project_gid ?? '';

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
        Gate::authorize('access-admin');

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
        Gate::authorize('access-admin');

        if ($this->confirmRemoveUserId === null) {
            return;
        }
        $this->project->users()->detach($this->confirmRemoveUserId);
        unset($this->userAssignments[$this->confirmRemoveUserId]);
        $this->confirmRemoveUserId = null;
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('access-admin');

        // Used by tests; UI flow goes through openRemoveUserModal → confirmRemoveUser.
        $this->project->users()->detach($userId);
        unset($this->userAssignments[$userId]);
    }

    public function save(AsanaService $asana): void
    {
        Gate::authorize('access-admin');

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
            'asanaProjectGid' => [
                'nullable',
                'string',
                'exists:asana_projects,gid',
                Rule::unique('projects', 'asana_project_gid')->ignore($this->project->id),
            ],
        ], [
            'asanaProjectGid.unique' => 'Another project is already linked to this Asana project.',
        ]);

        $previousGid = $this->project->asana_project_gid;
        $newGid = $this->asanaProjectGid !== '' ? $this->asanaProjectGid : null;
        $newWorkspaceGid = null;
        $newCustomFieldGid = $this->project->asana_custom_field_gid;

        if ($newGid !== null && $newGid !== $previousGid) {
            $cached = AsanaProject::find($newGid);
            $newWorkspaceGid = $cached?->workspace_gid;
            $newCustomFieldGid = null;

            $authUser = $this->authUser();
            if ($newWorkspaceGid !== null && $authUser->asanaConnected()) {
                try {
                    $newCustomFieldGid = $asana->forUser($authUser)
                        ->ensureHoursCustomField($newGid, $newWorkspaceGid);
                } catch (\Throwable $e) {
                    AsanaSyncLog::error('asana.project_link.custom_field_failed', [
                        'asana_project_gid' => $newGid,
                        'error' => $e->getMessage(),
                    ], $this->project);
                    session()->flash('asana_warning', 'Project linked, but the cumulative-hours custom field could not be set up. It will be retried on the first time entry sync.');
                }
            }
        } elseif ($newGid === null) {
            $newWorkspaceGid = null;
            $newCustomFieldGid = null;
        } else {
            // unchanged
            $newWorkspaceGid = $this->project->asana_workspace_gid;
        }

        $this->project->update([
            'client_id' => $this->clientId,
            'manager_user_id' => $this->managerUserId,
            'code' => $this->code ?: null,
            'name' => $this->name,
            'is_billable' => $this->isBillable,
            'starts_on' => $this->startsOn ?: null,
            'ends_on' => $this->endsOn ?: null,
            'budget_type' => $this->budgetType !== '' ? BudgetType::from($this->budgetType) : null,
            'budget_amount' => $this->budgetType !== '' && $this->budgetAmount !== '' ? (float) $this->budgetAmount : null,
            'budget_hours' => $this->budgetType !== '' && $this->budgetHours !== '' ? (float) $this->budgetHours : null,
            'budget_starts_on' => $this->budgetType === 'monthly_ci' && $this->budgetStartsOn !== '' ? $this->budgetStartsOn : null,
            'asana_project_gid' => $newGid,
            'asana_workspace_gid' => $newWorkspaceGid,
            'asana_custom_field_gid' => $newCustomFieldGid,
        ]);

        $authUser = $this->authUser();
        if ($newGid !== null && $newGid !== $previousGid && $authUser->asanaConnected()) {
            PullAsanaTasksJob::dispatch($newGid, $authUser->id);
        }

        // Sync tasks. Billability is now sourced from task.is_default_billable
        // (managed on the global Tasks admin page); the pivot column is kept in
        // sync for backward compat but isn't read by the resolver any more.
        $assignedIds = array_values(array_unique(array_map('intval', $this->taskAssignments)));
        $defaults = Task::whereIn('id', $assignedIds)->pluck('is_default_billable', 'id');
        $taskSync = [];
        foreach ($assignedIds as $taskId) {
            $taskSync[$taskId] = ['is_billable' => (bool) ($defaults[$taskId] ?? false)];
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

        session()->flash('status', 'Project saved.');
    }

    public function refreshAsanaTasks(): void
    {
        Gate::authorize('access-admin');

        $authUser = $this->authUser();
        if (! $authUser->asanaConnected() || $this->project->asana_project_gid === null) {
            return;
        }

        PullAsanaTasksJob::dispatch($this->project->asana_project_gid, $authUser->id);
        session()->flash('status', 'Refreshing Asana tasks in the background.');
    }

    public function render(ProjectBudgetCalculator $budgetCalculator): View
    {
        $authUser = $this->authUser();
        $workspaceGid = $authUser->asana_workspace_gid;
        $linkedGids = Project::query()
            ->whereNotNull('asana_project_gid')
            ->where('id', '!=', $this->project->id)
            ->pluck('asana_project_gid');

        $asanaProjects = $workspaceGid !== null
            ? AsanaProject::query()
                ->where('workspace_gid', $workspaceGid)
                ->where('is_archived', false)
                ->whereNotIn('gid', $linkedGids)
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
}
