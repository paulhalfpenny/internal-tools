<?php

namespace App\Livewire\Reports;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Domain\Reporting\TotalsDto;
use App\Enums\GroupBy;
use App\Livewire\Reports\Concerns\HasReportPeriod;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ClientDetail extends Component
{
    use HasReportPeriod;

    public Client $client;

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->mountHasReportPeriod();
    }

    public function totals(): TotalsDto
    {
        return $this->buildQuery(clientId: $this->client->id)->totals();
    }

    /** @return Collection<int, \stdClass> */
    public function rows(ProjectBudgetCalculator $calculator): Collection
    {
        $rows = $this->buildQuery(clientId: $this->client->id)->groupBy(GroupBy::Project);

        $projects = $this->client->projects()->whereIn('id', $rows->pluck('id')->all())->get();
        $statuses = $calculator->forProjects($projects);

        return $rows->map(function (\stdClass $row) use ($statuses): \stdClass {
            $row->budget_status = $statuses[$row->id] ?? null;

            return $row;
        });
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        return $this->exportCsv(clientId: $this->client->id);
    }

    #[Renderless]
    public function exportForProject(int $projectId): StreamedResponse
    {
        return $this->exportCsv(projectId: $projectId);
    }

    public function render(ProjectBudgetCalculator $calculator): View
    {
        return view('livewire.reports.client-detail', [
            'totals' => $this->totals(),
            'rows' => $this->rows($calculator),
        ]);
    }
}
