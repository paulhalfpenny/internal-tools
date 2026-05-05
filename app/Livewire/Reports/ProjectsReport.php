<?php

namespace App\Livewire\Reports;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Domain\Reporting\TotalsDto;
use App\Enums\GroupBy;
use App\Livewire\Reports\Concerns\HasReportPeriod;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ProjectsReport extends Component
{
    use HasReportPeriod;

    public function mount(): void
    {
        $this->mountHasReportPeriod();
    }

    public function totals(): TotalsDto
    {
        return $this->buildQuery()->totals();
    }

    /** @return Collection<int, \stdClass> */
    public function rows(ProjectBudgetCalculator $calculator): Collection
    {
        $rows = $this->buildQuery()->groupBy(GroupBy::Project);

        $projectIds = $rows->pluck('id')->all();
        if (empty($projectIds)) {
            return $rows;
        }

        $projects = Project::query()->whereIn('id', $projectIds)->get();
        $statuses = $calculator->forProjects($projects);

        return $rows->map(function (\stdClass $row) use ($statuses): \stdClass {
            $row->budget_status = $statuses[$row->id] ?? null;

            return $row;
        });
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        return $this->exportCsv();
    }

    public function render(ProjectBudgetCalculator $calculator): View
    {
        return view('livewire.reports.projects-report', [
            'totals' => $this->totals(),
            'rows' => $this->rows($calculator),
        ]);
    }
}
