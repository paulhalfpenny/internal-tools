<?php

namespace App\Livewire\Reports;

use App\Domain\Reporting\JdwReportQuery;
use App\Domain\Reporting\JdwXlsxExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class JdwReport extends Component
{
    public string $month = '';

    public function mount(): void
    {
        $this->month = CarbonImmutable::now()->subMonth()->format('Y-m');
    }

    /**
     * @return array<string, float|null>
     */
    #[Computed]
    public function programmeRow(): array
    {
        return $this->query()->programmeRow();
    }

    /**
     * @return Collection<int, array{id: int, name: string, code: string|null, jdw_sort_order: int|null, jdw_status: string|null, jdw_estimated_launch: string|null, jdw_description: string|null, hours: array<string, float|null>}>
     */
    #[Computed]
    public function projectsRows(): Collection
    {
        return $this->query()->projectsRows();
    }

    /**
     * @return Collection<int, array{id: int, name: string, code: string|null, jdw_sort_order: int|null, jdw_status: string|null, jdw_estimated_launch: string|null, jdw_description: string|null, hours: array<string, float|null>}>
     */
    #[Computed]
    public function smRows(): Collection
    {
        return $this->query()->smRows();
    }

    #[Renderless]
    public function export(): BinaryFileResponse
    {
        $carbonMonth = $this->carbonMonth();
        $query = $this->query();
        $export = new JdwXlsxExport($carbonMonth, $query);
        $filename = 'jdw-time-report-'.$carbonMonth->format('Y-m').'.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * Generate the TSV string for the Programme block (single row, 17 columns).
     *
     * @param  string[]  $tasks
     * @param  array<string, float|null>  $hours
     */
    public function programmeTsvRow(array $tasks, array $hours): string
    {
        return implode("\t", array_map(
            fn (string $task) => $hours[$task] !== null ? number_format((float) $hours[$task], 2, '.', '') : '',
            $tasks
        ));
    }

    /**
     * Generate the TSV string for a multi-row block (Projects or S&M).
     * Produces one line per project, tab-separated hours only (no project name/code/total).
     *
     * @param  string[]  $tasks
     * @param  Collection<int, array{hours: array<string, float|null>}>  $rows
     */
    public function blockTsv(array $tasks, Collection $rows): string
    {
        return $rows->map(function (array $project) use ($tasks): string {
            return implode("\t", array_map(
                fn (string $task) => $project['hours'][$task] !== null
                    ? number_format((float) $project['hours'][$task], 2, '.', '')
                    : '',
                $tasks
            ));
        })->implode("\n");
    }

    public function render(): View
    {
        return view('livewire.reports.jdw-report', [
            'programmeTasks' => JdwReportQuery::PROGRAMME_TASKS,
            'projectsTasks' => JdwReportQuery::PROJECTS_TASKS,
            'smTasks' => JdwReportQuery::SM_TASKS,
        ]);
    }

    private function query(): JdwReportQuery
    {
        return new JdwReportQuery($this->carbonMonth());
    }

    private function carbonMonth(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01');
    }
}
