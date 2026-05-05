<?php

namespace App\Livewire\Admin\Projects;

use App\Enums\BudgetType;
use App\Models\Client;
use App\Models\Project;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public bool $showArchived = false;

    public int|string $clientId = '';

    public string $code = '';

    public string $name = '';

    public bool $isBillable = true;

    public string $defaultRate = '';

    public string $budgetType = '';

    public string $budgetAmount = '';

    public string $budgetHours = '';

    public string $budgetStartsOn = '';

    public function save(): void
    {
        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'code' => 'required|string|max:50|unique:projects,code',
            'name' => 'required|string|max:255',
            'isBillable' => 'boolean',
            'defaultRate' => 'nullable|numeric|min:0',
            'budgetType' => 'nullable|in:fixed_fee,monthly_ci',
            'budgetAmount' => 'nullable|numeric|min:0|required_with:budgetType',
            'budgetHours' => 'nullable|numeric|min:0',
            'budgetStartsOn' => 'nullable|date|required_if:budgetType,monthly_ci',
        ]);

        $project = Project::create([
            'client_id' => (int) $this->clientId,
            'code' => $this->code,
            'name' => $this->name,
            'is_billable' => $this->isBillable,
            'default_hourly_rate' => $this->defaultRate !== '' ? (float) $this->defaultRate : null,
            'budget_type' => $this->budgetType !== '' ? BudgetType::from($this->budgetType) : null,
            'budget_amount' => $this->budgetType !== '' && $this->budgetAmount !== '' ? (float) $this->budgetAmount : null,
            'budget_hours' => $this->budgetType !== '' && $this->budgetHours !== '' ? (float) $this->budgetHours : null,
            'budget_starts_on' => $this->budgetType === 'monthly_ci' && $this->budgetStartsOn !== '' ? $this->budgetStartsOn : null,
        ]);

        $this->redirect(route('admin.projects.edit', $project));
    }

    public function toggleArchive(int $projectId): void
    {
        $project = Project::findOrFail($projectId);
        $project->update(['is_archived' => ! $project->is_archived]);
    }

    public function render(): View
    {
        $query = Project::with('client')->orderBy('name');

        if (! $this->showArchived) {
            $query->where('is_archived', false);
        }

        return view('livewire.admin.projects.index', [
            'projects' => $query->get(),
            'clients' => Client::where('is_archived', false)->orderBy('name')->get(),
            'budgetTypes' => BudgetType::cases(),
        ]);
    }
}
