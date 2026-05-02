<?php

namespace App\Livewire\Admin\Projects;

use App\Enums\BillingType;
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

    public string $billingType;

    public string $defaultRate;

    public string $startsOn;

    public string $endsOn;

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
        $this->billingType = $project->billing_type->value;
        $this->defaultRate = $project->default_hourly_rate !== null ? (string) $project->default_hourly_rate : '';
        $this->startsOn = $project->starts_on?->toDateString() ?? '';
        $this->endsOn = $project->ends_on?->toDateString() ?? '';
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
            'billingType' => 'required|in:hourly,fixed_fee,non_billable',
            'defaultRate' => 'nullable|numeric|min:0',
            'startsOn' => 'nullable|date',
            'endsOn' => 'nullable|date',
            'jdwSortOrder' => 'nullable|integer|min:0',
        ]);

        $this->project->update([
            'client_id' => $this->clientId,
            'code' => $this->code ?: null,
            'name' => $this->name,
            'billing_type' => BillingType::from($this->billingType),
            'default_hourly_rate' => $this->defaultRate !== '' ? (float) $this->defaultRate : null,
            'starts_on' => $this->startsOn ?: null,
            'ends_on' => $this->endsOn ?: null,
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

    public function render(): View
    {
        return view('livewire.admin.projects.edit', [
            'clients' => Client::where('is_archived', false)->orderBy('name')->get(),
            'allTasks' => Task::where('is_archived', false)->orderBy('sort_order')->orderBy('name')->get(),
            'allUsers' => User::where('is_active', true)->orderBy('name')->get(),
            'billingTypes' => BillingType::cases(),
            'jdwCategories' => JdwCategory::cases(),
        ]);
    }
}
