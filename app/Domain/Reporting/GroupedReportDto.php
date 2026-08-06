<?php

namespace App\Domain\Reporting;

use Illuminate\Support\Collection;

final readonly class GroupedReportDto
{
    /**
     * @param  Collection<int, \stdClass>  $rows
     */
    public function __construct(
        public Collection $rows,
        public TotalsDto $totals,
    ) {}
}
