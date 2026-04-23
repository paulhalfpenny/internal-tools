<?php

namespace App\Livewire\Reports;

use App\Domain\Reporting\TotalsDto;
use App\Enums\GroupBy;
use App\Livewire\Reports\Concerns\HasReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TasksReport extends Component
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
    public function rows(): Collection
    {
        return $this->buildQuery()->groupBy(GroupBy::Task);
    }

    public function render(): View
    {
        $rows = $this->rows();
        $maxHours = $rows->max('total_hours') ?: 1.0;

        return view('livewire.reports.tasks-report', [
            'totals' => $this->totals(),
            'rows' => $rows,
            'maxHours' => (float) $maxHours,
        ]);
    }
}
