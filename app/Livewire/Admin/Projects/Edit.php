<?php

namespace App\Livewire\Admin\Projects;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Enums\JdwCategory;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public Project $project;

    public int $clientId;

    public ?string $code = null;

    public string $name;

    public bool $isBillable = true;

    public string $defaultRate;

    public string $startsOn;

    public string $endsOn;

    public string $budgetType = '';

    public string $budgetAmount = '';

    public string $budgetHours = '';

    public string $budgetStartsOn = '';

    // Task assignments: task_id => ['is_billable' => bool]
    /** @var array<int, array{is_billable: bool}> */
    public array $taskAssignments = [];

    // User assignments: user_id => ['hourly_rate_override' => string]
    /** @var array<int, array{hourly_rate_override: string}> */
    public array $userAssignments = [];

    // JDW fields
    public string $jdwCategory = '';

    public string $jdwSortOrder = '';

    public string $jdwStatus = '';

    public string $jdwEstimatedLaunch = '';

    public string $jdwDescription = '';

    public function mount(Project $project): void
    {
        $this->project = $project->load(['tasks', 'users']);
        $this->clientId = $project->client_id;
        $this->code = $project->code;
        $this->name = $project->name;
        $this->isBillable = (bool) $project->is_billable;
        $this->defaultRate = $project->default_hourly_rate !== null ? (string) $project->default_hourly_rate : '';
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
            $this->userAssignments[$userId] = ['hourly_rate_override' => ''];
        }
    }

    public function save(): void
    {
        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'code' => 'nullable|string|max:50|unique:projects,code,'.$this->project->id,
            'name' => 'required|string|max:255',
            'isBillable' => 'boolean',
            'defaultRate' => 'nullable|numeric|min:0',
            'startsOn' => 'nullable|date',
            'endsOn' => 'nullable|date',
            'jdwSortOrder' => 'nullable|integer|min:0',
            'budgetType' => 'nullable|in:fixed_fee,monthly_ci',
            'budgetAmount' => 'nullable|numeric|min:0|required_with:budgetType',
            'budgetHours' => 'nullable|numeric|min:0',
            'budgetStartsOn' => 'nullable|date|required_if:budgetType,monthly_ci',
        ]);

        $this->project->update([
            'client_id' => $this->clientId,
            'code' => $this->code ?: null,
            'name' => $this->name,
            'is_billable' => $this->isBillable,
            'default_hourly_rate' => $this->defaultRate !== '' ? (float) $this->defaultRate : null,
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
        ]);

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
            $userSync[$userId] = ['hourly_rate_override' => $override];
        }
        $this->project->users()->sync($userSync);

        session()->flash('status', 'Project saved.');
    }

    public function render(ProjectBudgetCalculator $budgetCalculator): View
    {
        return view('livewire.admin.projects.edit', [
            'clients' => Client::where('is_archived', false)->orderBy('name')->get(),
            'allTasks' => Task::where('is_archived', false)->orderBy('sort_order')->orderBy('name')->get(),
            'allUsers' => User::where('is_active', true)->orderBy('name')->get(),
            'budgetTypes' => BudgetType::cases(),
            'jdwCategories' => JdwCategory::cases(),
            'budgetStatus' => $this->project->budget_type !== null ? $budgetCalculator->forProject($this->project) : null,
        ]);
    }
}
