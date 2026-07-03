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
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ProjectBudget extends Component
{
    use WithPagination;

    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project->load('client');
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        [$from, $to] = $this->lifetimeWindow();

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
        [$from, $to] = $this->lifetimeWindow();

        $entries = (new TimeReportQuery(
            from: $from,
            to: $to,
            projectId: $this->project->id,
        ))->paginate();

        return view('livewire.reports.project-budget', [
            'status' => $calculator->forProject($this->project),
            'monthlyRows' => $calculator->monthlyBreakdown($this->project),
            'entries' => $entries,
        ]);
    }

    /**
     * The project's effective lifetime window: its budget/project start date
     * through today. Falls back to the earliest logged entry, then to a year
     * ago, when no start date is configured. Shared by export() and render()
     * so the CSV and the on-screen entries table cover the same range.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function lifetimeWindow(): array
    {
        $start = $this->project->budget_starts_on ?? $this->project->starts_on;
        if ($start === null) {
            $earliest = TimeEntry::where('project_id', $this->project->id)->min('spent_on');
            $start = $earliest ?? CarbonImmutable::now()->subYear()->toDateString();
        }

        return [CarbonImmutable::parse($start), CarbonImmutable::now()->endOfDay()];
    }
}
