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
class TeamOverviewReport extends Component
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
        return $this->buildQuery()->groupBy(GroupBy::User);
    }

    public function render(): View
    {
        return view('livewire.reports.team-overview-report', [
            'totals' => $this->totals(),
            'rows' => $this->rows(),
        ]);
    }
}
