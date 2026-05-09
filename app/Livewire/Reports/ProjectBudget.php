<?php

namespace App\Livewire\Reports;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ProjectBudget extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project->load('client');
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        // Match the lifetime scope shown on screen: project's effective start to today.
        // If neither budget_starts_on nor starts_on is set, fall back to the earliest entry date.
        $start = $this->project->budget_starts_on ?? $this->project->starts_on;
        if ($start === null) {
            $earliest = TimeEntry::where('project_id', $this->project->id)->min('spent_on');
            $start = $earliest ?? CarbonImmutable::now()->subYear()->toDateString();
        }
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::now()->endOfDay();

        $query = new TimeReportQuery(
            from: $from,
            to: $to,
            projectId: $this->project->id,
        );
        $export = new DetailedTimeCsvExport($query);

        $slug = Str::slug($this->project->code !== '' ? $this->project->code : $this->project->name);
        $filename = 'detailed-time-'.$slug.'-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($export): void {
            $handle = fopen('php://output', 'w');
            assert($handle !== false);
            $export->writeTo($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(ProjectBudgetCalculator $calculator): View
    {
        return view('livewire.reports.project-budget', [
            'status' => $calculator->forProject($this->project),
            'monthlyRows' => $calculator->monthlyBreakdown($this->project),
        ]);
    }
}
