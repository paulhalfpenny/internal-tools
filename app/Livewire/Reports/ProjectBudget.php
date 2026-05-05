<?php

namespace App\Livewire\Reports;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Models\Project;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProjectBudget extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project->load('client');
    }

    public function render(ProjectBudgetCalculator $calculator): View
    {
        return view('livewire.reports.project-budget', [
            'status' => $calculator->forProject($this->project),
            'monthlyRows' => $calculator->monthlyBreakdown($this->project),
        ]);
    }
}
