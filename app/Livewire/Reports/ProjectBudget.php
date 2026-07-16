<?php

namespace App\Livewire\Reports;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Domain\TimeTracking\HoursFormatter;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
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

    public ?string $filterMonth = null;

    public ?string $filterFrom = null;

    public ?string $filterTo = null;

    public ?int $filterTaskId = null;

    public ?int $filterUserId = null;

    public function updating(string $name, mixed $value): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->resetPage();
        }
    }

    public function updatedFilterMonth(): void
    {
        // Month and custom range are mutually exclusive.
        $this->filterFrom = null;
        $this->filterTo = null;
    }

    public function updatedFilterFrom(): void
    {
        $this->filterMonth = null;
    }

    public function updatedFilterTo(): void
    {
        $this->filterMonth = null;
    }

    public function clearFilters(): void
    {
        $this->reset(['filterMonth', 'filterFrom', 'filterTo', 'filterTaskId', 'filterUserId']);
        $this->resetPage();
    }

    /**
     * The date window the entries list & totals should cover, honouring the
     * active filters. Month wins over custom range; custom range wins over the
     * default lifetime window; each custom bound is optional.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function filteredWindow(): array
    {
        if (filled($this->filterMonth)) {
            $month = CarbonImmutable::parse($this->filterMonth.'-01');

            return [$month->startOfMonth(), $month->endOfMonth()];
        }

        if (filled($this->filterFrom) || filled($this->filterTo)) {
            [$lifeFrom, $lifeTo] = $this->lifetimeWindow();
            $from = filled($this->filterFrom) ? CarbonImmutable::parse($this->filterFrom) : $lifeFrom;
            $to = filled($this->filterTo) ? CarbonImmutable::parse($this->filterTo)->endOfDay() : $lifeTo;

            return [$from, $to];
        }

        return $this->lifetimeWindow();
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
        [$from, $to] = $this->filteredWindow();

        $query = new TimeReportQuery(
            from: $from,
            to: $to,
            userId: $this->filterUserId,
            projectId: $this->project->id,
            taskId: $this->filterTaskId,
        );

        $entries = $query->paginate();
        $filteredTotals = $query->totals();

        $hasFilters = filled($this->filterMonth)
            || filled($this->filterFrom)
            || filled($this->filterTo)
            || $this->filterUserId !== null
            || $this->filterTaskId !== null;

        $projectId = $this->project->id;

        $monthOptions = TimeEntry::query()
            ->where('project_id', $projectId)
            ->orderByDesc('spent_on')
            ->pluck('spent_on')
            ->map(fn ($date) => $date->format('Y-m'))
            ->unique()
            ->values()
            ->map(fn (string $ym) => [
                'value' => $ym,
                'label' => CarbonImmutable::parse($ym.'-01')->format('F Y'),
            ]);

        $taskOptions = Task::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $projectId)->select('task_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $userOptions = User::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $projectId)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        /** @var User|null $user */
        $user = auth()->user();

        return view('livewire.reports.project-budget', [
            'status' => $calculator->forProject($this->project),
            'monthlyRows' => $calculator->monthlyBreakdown($this->project),
            'entries' => $entries,
            'filteredTotals' => $filteredTotals,
            'hasFilters' => $hasFilters,
            'monthOptions' => $monthOptions,
            'taskOptions' => $taskOptions,
            'userOptions' => $userOptions,
            'hoursFormat' => $user?->hoursDisplayFormat() ?? HoursFormatter::FORMAT_HHMM,
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
