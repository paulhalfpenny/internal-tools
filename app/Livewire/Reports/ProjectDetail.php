<?php

namespace App\Livewire\Reports;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ProjectDetail extends Component
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
     * default lifetime window; each custom bound is optional. Invalid/garbage
     * filter values (public props can be set to anything by the client) are
     * ignored rather than allowed to crash render().
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function filteredWindow(): array
    {
        $month = $this->parseMonth($this->filterMonth);
        if ($month !== null) {
            return [$month->startOfMonth(), $month->endOfMonth()];
        }

        $from = $this->parseDate($this->filterFrom);
        $to = $this->parseDate($this->filterTo);
        if ($from !== null || $to !== null) {
            [$lifeFrom, $lifeTo] = $this->lifetimeWindow();

            // baseQuery() compares spent_on at date granularity, so the bounds
            // need no time component.
            return [$from ?? $lifeFrom, $to ?? $lifeTo];
        }

        return $this->lifetimeWindow();
    }

    private function parseMonth(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value.'-01');
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        [$from, $to] = $this->filteredWindow();

        $query = new TimeReportQuery(
            from: $from,
            to: $to,
            userId: $this->filterUserId,
            projectId: $this->project->id,
            taskId: $this->filterTaskId,
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
        $status = $calculator->forProject($this->project);

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

        return view('livewire.reports.project-detail', [
            'status' => $status,
            'monthlyRows' => $status === null ? collect() : $calculator->monthlyBreakdown($this->project),
            'monthlySpend' => $calculator->monthlySpend($this->project),
            'entries' => $entries,
            'filteredTotals' => $filteredTotals,
            'hasFilters' => $hasFilters,
            'monthOptions' => $this->monthOptions(),
            'taskOptions' => $this->taskOptions(),
            'userOptions' => $this->userOptions(),
        ]);
    }

    /**
     * Distinct months (newest first) that this project has time entries in,
     * as ['value' => 'Y-m', 'label' => 'F Y'] for the month dropdown.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    private function monthOptions(): Collection
    {
        return TimeEntry::query()
            ->where('project_id', $this->project->id)
            ->orderByDesc('spent_on')
            ->pluck('spent_on')
            ->map(fn ($date) => $date->format('Y-m'))
            ->unique()
            ->values()
            ->map(fn (string $ym) => [
                'value' => $ym,
                'label' => CarbonImmutable::parse($ym.'-01')->format('F Y'),
            ]);
    }

    /**
     * Tasks that appear on this project's time entries, for the task dropdown.
     *
     * @return Collection<int, Task>
     */
    private function taskOptions(): Collection
    {
        return Task::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $this->project->id)->select('task_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Users that appear on this project's time entries, for the user dropdown.
     *
     * @return Collection<int, User>
     */
    private function userOptions(): Collection
    {
        return User::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $this->project->id)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
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
