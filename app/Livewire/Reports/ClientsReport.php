<?php

namespace App\Livewire\Reports;

use App\Domain\Reporting\TotalsDto;
use App\Enums\GroupBy;
use App\Livewire\Reports\Concerns\HasReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ClientsReport extends Component
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
        return $this->buildQuery()->groupBy(GroupBy::Client);
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        return $this->exportCsv();
    }

    public function render(): View
    {
        return view('livewire.reports.clients-report', [
            'totals' => $this->totals(),
            'rows' => $this->rows(),
        ]);
    }
}
