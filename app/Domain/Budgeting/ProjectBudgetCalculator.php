<?php

namespace App\Domain\Budgeting;

use App\Enums\BudgetType;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ProjectBudgetCalculator
{
    public function forProject(Project $project, ?CarbonImmutable $asOf = null): ?BudgetStatus
    {
        if ($project->budget_type === null) {
            return null;
        }

        $asOf = $asOf ?? CarbonImmutable::now();
        $start = $this->effectiveStart($project);

        $query = TimeEntry::query()
            ->where('project_id', $project->id)
            ->where('is_billable', true);

        if ($start !== null) {
            $query->where('spent_on', '>=', $start->toDateString());
        }

        $row = $query->toBase()
            ->selectRaw('
                COALESCE(SUM(hours), 0) as actual_hours,
                COALESCE(SUM(billable_amount), 0) as actual_amount
            ')
            ->first();

        $actualHours = (float) ($row->actual_hours ?? 0);
        $actualAmount = (float) ($row->actual_amount ?? 0);

        $effectiveBudget = $this->effectiveBudgetAmount($project, $asOf);

        return new BudgetStatus(
            budgetType: $project->budget_type,
            budgetAmount: $effectiveBudget,
            budgetHours: $project->budget_hours !== null ? (float) $project->budget_hours : null,
            actualAmount: round($actualAmount, 2),
            actualHours: round($actualHours, 2),
        );
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<int, BudgetStatus>
     */
    public function forProjects(Collection $projects, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $budgeted = $projects->filter(fn (Project $p) => $p->budget_type !== null);

        if ($budgeted->isEmpty()) {
            return [];
        }

        // Aggregate per-project in SQL using a CASE WHEN keyed on per-project
        // effective_start, so we never materialise individual time entries.
        $whenHours = [];
        $whenAmount = [];
        $hoursBindings = [];
        $amountBindings = [];
        foreach ($budgeted as $project) {
            $start = $this->effectiveStart($project);
            if ($start === null) {
                $whenHours[] = 'WHEN project_id = ? THEN hours';
                $whenAmount[] = 'WHEN project_id = ? THEN billable_amount';
                $hoursBindings[] = $project->id;
                $amountBindings[] = $project->id;
            } else {
                $whenHours[] = 'WHEN project_id = ? AND spent_on >= ? THEN hours';
                $whenAmount[] = 'WHEN project_id = ? AND spent_on >= ? THEN billable_amount';
                $hoursBindings[] = $project->id;
                $hoursBindings[] = $start->toDateString();
                $amountBindings[] = $project->id;
                $amountBindings[] = $start->toDateString();
            }
        }

        $hoursCase = 'SUM(CASE '.implode(' ', $whenHours).' ELSE 0 END) as actual_hours';
        $amountCase = 'SUM(CASE '.implode(' ', $whenAmount).' ELSE 0 END) as actual_amount';

        // Bindings must follow placeholder order in the SELECT: project_id (no
        // placeholders), then all of hoursCase's, then all of amountCase's.
        $rows = TimeEntry::query()
            ->whereIn('project_id', $budgeted->pluck('id'))
            ->where('is_billable', true)
            ->toBase()
            ->selectRaw("project_id, {$hoursCase}, {$amountCase}", array_merge($hoursBindings, $amountBindings))
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $result = [];
        foreach ($budgeted as $project) {
            $row = $rows->get($project->id);
            $result[$project->id] = new BudgetStatus(
                budgetType: $project->budget_type,
                budgetAmount: $this->effectiveBudgetAmount($project, $asOf),
                budgetHours: $project->budget_hours !== null ? (float) $project->budget_hours : null,
                actualAmount: round((float) ($row->actual_amount ?? 0), 2),
                actualHours: round((float) ($row->actual_hours ?? 0), 2),
            );
        }

        return $result;
    }

    /**
     * Per-month breakdown for the drill-down view.
     *
     * @return Collection<int, \stdClass>
     */
    public function monthlyBreakdown(Project $project, ?CarbonImmutable $asOf = null): Collection
    {
        if ($project->budget_type === null) {
            return collect();
        }

        $asOf = $asOf ?? CarbonImmutable::now();
        $start = $this->effectiveStart($project) ?? $asOf->startOfMonth();

        $months = [];
        $cursor = $start->startOfMonth();
        while ($cursor->lessThanOrEqualTo($asOf->startOfMonth())) {
            $months[] = $cursor;
            $cursor = $cursor->addMonth();
        }

        $rawEntries = TimeEntry::query()
            ->where('project_id', $project->id)
            ->where('is_billable', true)
            ->where('spent_on', '>=', $start->toDateString())
            ->toBase()
            ->selectRaw('spent_on, hours, billable_amount')
            ->get();

        $byMonth = [];
        foreach ($rawEntries as $entry) {
            $key = CarbonImmutable::parse($entry->spent_on)->format('Y-m');
            $byMonth[$key]['h'] = ($byMonth[$key]['h'] ?? 0) + (float) $entry->hours;
            $byMonth[$key]['a'] = ($byMonth[$key]['a'] ?? 0) + (float) $entry->billable_amount;
        }

        $rows = collect();
        $runningBudget = 0.0;
        $runningActual = 0.0;
        $runningHours = 0.0;

        foreach ($months as $i => $month) {
            $key = $month->format('Y-m');
            $monthAmount = (float) ($byMonth[$key]['a'] ?? 0);
            $monthHours = (float) ($byMonth[$key]['h'] ?? 0);

            if ($project->budget_type === BudgetType::FixedFee) {
                $monthBudget = $i === 0 ? (float) $project->budget_amount : 0.0;
            } else {
                $monthBudget = (float) $project->budget_amount;
            }

            $runningBudget += $monthBudget;
            $runningActual += $monthAmount;
            $runningHours += $monthHours;

            $rows->push((object) [
                'month' => $month,
                'month_label' => $month->format('M Y'),
                'month_budget' => round($monthBudget, 2),
                'month_amount' => round($monthAmount, 2),
                'month_hours' => round($monthHours, 2),
                'running_budget' => round($runningBudget, 2),
                'running_amount' => round($runningActual, 2),
                'running_hours' => round($runningHours, 2),
                'running_variance' => round($runningBudget - $runningActual, 2),
            ]);
        }

        return $rows;
    }

    private function effectiveStart(Project $project): ?CarbonImmutable
    {
        $start = $project->budget_starts_on ?? $project->starts_on;

        return $start ? CarbonImmutable::parse($start) : null;
    }

    private function effectiveBudgetAmount(Project $project, CarbonImmutable $asOf): float
    {
        $amount = (float) ($project->budget_amount ?? 0);

        if ($project->budget_type === BudgetType::FixedFee) {
            return $amount;
        }

        // Monthly CI: cumulative across whole calendar months from start to asOf inclusive.
        $start = $this->effectiveStart($project);
        if ($start === null) {
            return $amount;
        }

        $months = $this->monthsElapsed($start, $asOf);

        return round($amount * $months, 2);
    }

    private function monthsElapsed(CarbonImmutable $start, CarbonImmutable $asOf): int
    {
        $startMonth = $start->startOfMonth();
        $asOfMonth = $asOf->startOfMonth();

        if ($asOfMonth->lessThan($startMonth)) {
            return 0;
        }

        return $startMonth->diffInMonths($asOfMonth) + 1;
    }
}
