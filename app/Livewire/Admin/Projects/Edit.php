<?php

namespace App\Livewire\Admin\Projects;

use App\Domain\Billing\RateResolver;
use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Enums\JdwCategory;
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

    public string $defaultRate;

    public ?int $defaultRateId = null;

    public string $startsOn;

    public string $endsOn;

    public string $budgetType = '';

    public string $budgetAmount = '';

    public string $budgetHours = '';

    public string $budgetStartsOn = '';

    // Task assignments: task_id => ['is_billable' => bool]
    /** @var array<int, array{is_billable: bool}> */
    public array $taskAssignments = [];

    // User assignments: user_id => ['hourly_rate_override' => string, 'rate_id' => int|null]
    /** @var array<int, array{hourly_rate_override: string, rate_id: ?int}> */
    public array $userAssignments = [];

    // JDW fields
    public string $jdwCategory = '';

    public string $jdwSortOrder = '';

    public string $jdwStatus = '';

    public string $jdwEstimatedLaunch = '';

    public string $jdwDescription = '';

    public string $asanaProjectGid = '';

    public function mount(Project $project): void
    {
        $this->project = $project->load(['tasks', 'users']);
        $this->clientId = $project->client_id;
        $this->managerUserId = $project->manager_user_id;
        $this->code = $project->code;
        $this->name = $project->name;
        $this->isBillable = (bool) $project->is_billable;
        $this->defaultRate = $project->default_hourly_rate !== null ? (string) $project->default_hourly_rate : '';
        $this->defaultRateId = $project->rate_id;
        $this->startsOn = $project->starts_on?->toDateString() ?? '';
        $this->endsOn = $project->ends_on?->toDateString() ?? '';
        $this->budgetType = $project->budget_type?->value ?? '';
        $this->budgetAmount = $project->budget_amount !== null ? (string) $project->budget_amount : '';
        $this->budgetHours = $project->budget_hours !== null ? (string) $project->budget_hours : '';
        $this->budgetStartsOn = $project->budget_starts_on?->toDateString() ?? '';
        $this->jdwCategory = $project->jdw_category?->value ?? '';
        $this->jdwSortOrder = $project->jdw_sort_order !== null ? (string) $project->jdw_sort_order : '';
        $this->jdwStatus = $project->jdw_status ?? '';
        $this->jdwEstimatedLaunch = $project->jdw_estimated_launch ?? '';
        $this->jdwDescription = $project->jdw_description ?? '';
        $this->asanaProjectGid = $project->asana_project_gid ?? '';

        foreach ($project->tasks as $task) {
            /** @var Pivot $pivot */
            $pivot = $task->getRelation('pivot');
            $this->taskAssignments[$task->id] = ['is_billable' => (bool) $pivot->getAttribute('is_billable')];
        }

        foreach ($project->users as $user) {
            /** @var Pivot $pivot */
            $pivot = $user->getRelation('pivot');
            $this->userAssignments[$user->id] = [
                'hourly_rate_override' => $pivot->getAttribute('hourly_rate_override') !== null
                    ? (string) $pivot->getAttribute('hourly_rate_override')
                    : '',
                'rate_id' => $pivot->getAttribute('rate_id'),
            ];
        }
    }

    public function toggleTask(int $taskId, bool $defaultBillable): void
    {
        if (isset($this->taskAssignments[$taskId])) {
            unset($this->taskAssignments[$taskId]);
        } else {
            $this->taskAssignments[$taskId] = ['is_billable' => $defaultBillable];
        }
    }

    public function toggleUser(int $userId): void
    {
        if (isset($this->userAssignments[$userId])) {
            unset($this->userAssignments[$userId]);
        } else {
            $this->userAssignments[$userId] = ['hourly_rate_override' => '', 'rate_id' => null];
        }
    }

    public function save(AsanaService $asana): void
    {
        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'managerUserId' => 'nullable|exists:users,id',
            'code' => 'nullable|string|max:50|unique:projects,code,'.$this->project->id,
            'name' => 'required|string|max:255',
            'isBillable' => 'boolean',
            'defaultRate' => 'nullable|numeric|min:0',
            'defaultRateId' => 'nullable|exists:rates,id',
            'startsOn' => 'nullable|date',
            'endsOn' => 'nullable|date',
            'jdwSortOrder' => 'nullable|integer|min:0',
            'budgetType' => 'nullable|in:fixed_fee,monthly_ci',
            'budgetAmount' => 'nullable|numeric|min:0|required_with:budgetType',
            'budgetHours' => 'nullable|numeric|min:0',
            'budgetStartsOn' => 'nullable|date|required_if:budgetType,monthly_ci',
            'asanaProjectGid' => 'nullable|string|exists:asana_projects,gid',
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
            'default_hourly_rate' => $this->defaultRate !== '' ? (float) $this->defaultRate : null,
            'rate_id' => $this->defaultRateId,
            'starts_on' => $this->startsOn ?: null,
            'ends_on' => $this->endsOn ?: null,
            'budget_type' => $this->budgetType !== '' ? BudgetType::from($this->budgetType) : null,
            'budget_amount' => $this->budgetType !== '' && $this->budgetAmount !== '' ? (float) $this->budgetAmount : null,
            'budget_hours' => $this->budgetType !== '' && $this->budgetHours !== '' ? (float) $this->budgetHours : null,
            'budget_starts_on' => $this->budgetType === 'monthly_ci' && $this->budgetStartsOn !== '' ? $this->budgetStartsOn : null,
            'jdw_category' => $this->jdwCategory !== '' ? JdwCategory::from($this->jdwCategory) : null,
            'jdw_sort_order' => $this->jdwSortOrder !== '' ? (int) $this->jdwSortOrder : null,
            'jdw_status' => $this->jdwStatus ?: null,
            'jdw_estimated_launch' => $this->jdwEstimatedLaunch ?: null,
            'jdw_description' => $this->jdwDescription ?: null,
            'asana_project_gid' => $newGid,
            'asana_workspace_gid' => $newWorkspaceGid,
            'asana_custom_field_gid' => $newCustomFieldGid,
        ]);

        $authUser = $this->authUser();
        if ($newGid !== null && $newGid !== $previousGid && $authUser->asanaConnected()) {
            PullAsanaTasksJob::dispatch($newGid, $authUser->id);
        }

        // Sync tasks
        $taskSync = [];
        foreach ($this->taskAssignments as $taskId => $data) {
            $taskSync[$taskId] = ['is_billable' => $data['is_billable']];
        }
        $this->project->tasks()->sync($taskSync);

        // Sync users
        $userSync = [];
        foreach ($this->userAssignments as $userId => $data) {
            $override = $data['hourly_rate_override'] !== '' ? (float) $data['hourly_rate_override'] : null;
            $userSync[$userId] = [
                'hourly_rate_override' => $override,
                'rate_id' => $data['rate_id'] ?? null,
            ];
        }
        $this->project->users()->sync($userSync);

        session()->flash('status', 'Project saved.');
    }

    public function refreshAsanaTasks(): void
    {
        $authUser = $this->authUser();
        if (! $authUser->asanaConnected() || $this->project->asana_project_gid === null) {
            return;
        }

        PullAsanaTasksJob::dispatch($this->project->asana_project_gid, $authUser->id);
        session()->flash('status', 'Refreshing Asana tasks in the background.');
    }

    public function render(ProjectBudgetCalculator $budgetCalculator, RateResolver $rateResolver): View
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

        $rates = Rate::where('is_archived', false)->orderBy('name')->get();

        // Resolved-rate matrix for the team-rates table. Resolution uses *current*
        // form state (Alpine-bound $this->userAssignments) for the per-user override,
        // not the saved pivot, so the user sees what their changes will produce.
        $effectiveRates = [];
        $allUsers = User::where('is_active', true)->orderBy('name')->get();
        $usersById = $allUsers->keyBy('id');
        $tasks = $this->project->tasks; // already loaded
        $defaultTask = $tasks->first();

        if ($defaultTask !== null) {
            // Build a transient project clone reflecting unsaved form values for resolution
            $transient = $this->project->replicate();
            $transient->setRelations($this->project->getRelations());
            $transient->is_billable = $this->isBillable;
            $transient->default_hourly_rate = $this->defaultRate !== '' ? (float) $this->defaultRate : null;
            $transient->rate_id = $this->defaultRateId;

            foreach ($this->userAssignments as $userId => $data) {
                $user = $usersById->get($userId);
                if ($user === null) {
                    continue;
                }

                // Build a one-user collection with the unsaved pivot values so RateResolver picks them up.
                $userClone = $user->replicate();
                $userClone->id = $user->id;
                $pivot = new Pivot;
                $pivot->setAttribute('hourly_rate_override', $data['hourly_rate_override'] !== '' ? (float) $data['hourly_rate_override'] : null);
                $pivot->setAttribute('rate_id', $data['rate_id'] ?? null);
                $userClone->setRelation('pivot', $pivot);

                $transient->setRelation('users', collect([$userClone]));
                $resolution = $rateResolver->resolve($transient, $defaultTask, $user);

                $effectiveRates[$userId] = $resolution->rateSnapshot;
            }
        }

        return view('livewire.admin.projects.edit', [
            'clients' => Client::where('is_archived', false)->orderBy('name')->get(),
            'allTasks' => Task::where('is_archived', false)->orderBy('sort_order')->orderBy('name')->get(),
            'allUsers' => $allUsers,
            'budgetTypes' => BudgetType::cases(),
            'jdwCategories' => JdwCategory::cases(),
            'budgetStatus' => $this->project->budget_type !== null ? $budgetCalculator->forProject($this->project) : null,
            'asanaProjects' => $asanaProjects,
            'asanaConnected' => $authUser->asanaConnected(),
            'rates' => $rates,
            'effectiveRates' => $effectiveRates,
        ]);
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
