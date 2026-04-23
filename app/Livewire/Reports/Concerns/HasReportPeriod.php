<?php

namespace App\Livewire\Reports\Concerns;

use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HasReportPeriod
{
    public string $preset = 'this_month';

    public string $from = '';

    public string $to = '';

    public bool $activeProjectsOnly = false;

    public bool $includeFixedFee = false;

    public function mountHasReportPeriod(): void
    {
        $this->applyPreset($this->preset);
    }

    public function updatedPreset(string $value): void
    {
        if ($value !== 'custom') {
            $this->applyPreset($value);
        }
    }

    private function applyPreset(string $preset): void
    {
        $now = CarbonImmutable::now();

        match ($preset) {
            'this_week' => [$this->from, $this->to] = [$now->startOfWeek()->toDateString(), $now->endOfWeek()->toDateString()],
            'this_month' => [$this->from, $this->to] = [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()],
            'last_month' => [$this->from, $this->to] = [$now->subMonth()->startOfMonth()->toDateString(), $now->subMonth()->endOfMonth()->toDateString()],
            'last_3' => [$this->from, $this->to] = [$now->subMonths(3)->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()],
            'this_year' => [$this->from, $this->to] = [$now->startOfYear()->toDateString(), $now->endOfYear()->toDateString()],
            'last_year' => [$this->from, $this->to] = [$now->subYear()->startOfYear()->toDateString(), $now->subYear()->endOfYear()->toDateString()],
            default => null,
        };
    }

    public function exportCsv(?int $userId = null): StreamedResponse
    {
        $query = $this->buildQuery($userId);
        $export = new DetailedTimeCsvExport($query);
        $filename = 'detailed-time-'.$this->from.'-to-'.$this->to.'.csv';

        return response()->streamDownload(function () use ($export): void {
            $handle = fopen('php://output', 'w');
            assert($handle !== false);
            $export->writeTo($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function buildQuery(?int $userId = null): TimeReportQuery
    {
        return new TimeReportQuery(
            from: CarbonImmutable::parse($this->from),
            to: CarbonImmutable::parse($this->to),
            userId: $userId,
            activeProjectsOnly: $this->activeProjectsOnly,
            includeFixedFee: $this->includeFixedFee,
        );
    }
}
