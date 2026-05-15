<?php

namespace App\Domain\Schedule;

use App\Models\ScheduleAssignment;
use App\Models\SchedulePlaceholder;
use App\Models\ScheduleTimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ScheduleAvailabilityService
{
    /**
     * @return array<int, array{index: int, starts_on: string, ends_on: string, label: string, sublabel: string, is_today: bool, is_current: bool}>
     */
    public function periods(string $scale, string $selectedDate): array
    {
        $selected = CarbonImmutable::parse($selectedDate);

        if ($scale === 'day') {
            $start = $selected->startOfWeek();

            return collect(range(0, 41))
                ->map(function (int $offset) use ($start, $selected) {
                    $day = $start->addDays($offset);

                    return [
                        'index' => $offset,
                        'starts_on' => $day->toDateString(),
                        'ends_on' => $day->toDateString(),
                        'label' => $day->format('D'),
                        'sublabel' => $day->format('j'),
                        'is_today' => $day->isToday(),
                        'is_current' => $day->isSameDay($selected),
                    ];
                })
                ->all();
        }

        if ($scale === 'month') {
            $start = $selected->startOfMonth();

            return collect(range(0, 11))
                ->map(function (int $offset) use ($start, $selected) {
                    $month = $start->addMonths($offset);

                    return [
                        'index' => $offset,
                        'starts_on' => $month->toDateString(),
                        'ends_on' => $month->endOfMonth()->toDateString(),
                        'label' => $month->format('Y'),
                        'sublabel' => $month->format('M'),
                        'is_today' => $month->isSameMonth(today()),
                        'is_current' => $month->isSameMonth($selected),
                    ];
                })
                ->all();
        }

        $start = $selected->startOfWeek();

        return collect(range(0, 11))
            ->map(function (int $offset) use ($start, $selected) {
                $week = $start->addWeeks($offset);

                return [
                    'index' => $offset,
                    'starts_on' => $week->toDateString(),
                    'ends_on' => $week->addDays(6)->toDateString(),
                    'label' => $week->format('M'),
                    'sublabel' => $week->format('j'),
                    'is_today' => today()->betweenIncluded($week, $week->addDays(6)),
                    'is_current' => $selected->betweenIncluded($week, $week->addDays(6)),
                ];
            })
            ->all();
    }

    public function capacityForPeriod(User|SchedulePlaceholder $assignee, CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): float
    {
        $workDays = $assignee->effectiveScheduleWorkDays();
        $dailyCapacity = $this->dailyCapacity($assignee);

        return round($this->workingDaysBetween($startsOn, $endsOn, $workDays) * $dailyCapacity, 2);
    }

    public function dailyCapacity(User|SchedulePlaceholder $assignee): float
    {
        $workDays = $assignee->effectiveScheduleWorkDays();
        $weeklyCapacity = $assignee instanceof User
            ? $assignee->effectiveWeeklyTarget()
            : (float) $assignee->weekly_capacity_hours;

        return round($weeklyCapacity / max(count($workDays), 1), 2);
    }

    public function assignmentHoursForPeriod(ScheduleAssignment $assignment, CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): float
    {
        $assignee = $assignment->placeholder ?? $assignment->user;
        if (! $assignee) {
            return 0.0;
        }

        $overlap = $this->overlap(
            $assignment->starts_on,
            $assignment->ends_on,
            $startsOn,
            $endsOn,
        );

        if ($overlap === null) {
            return 0.0;
        }

        return round($this->calendarDaysBetween($overlap[0], $overlap[1]) * (float) $assignment->hours_per_day, 2);
    }

    public function timeOffHoursForPeriod(ScheduleTimeOff $timeOff, CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): float
    {
        $overlap = $this->overlap(
            $timeOff->starts_on,
            $timeOff->ends_on,
            $startsOn,
            $endsOn,
        );

        if ($overlap === null) {
            return 0.0;
        }

        $workDays = $timeOff->user->effectiveScheduleWorkDays();

        return round($this->workingDaysBetween($overlap[0], $overlap[1], $workDays) * (float) $timeOff->hours_per_day, 2);
    }

    /**
     * @param  iterable<ScheduleAssignment>  $assignments
     * @param  iterable<ScheduleTimeOff>  $timeOff
     * @return array{capacity: float, scheduled: float, time_off: float, availability: float}
     */
    public function summaryForPeriod(
        User|SchedulePlaceholder $assignee,
        iterable $assignments,
        iterable $timeOff,
        CarbonInterface|string $startsOn,
        CarbonInterface|string $endsOn,
    ): array {
        $capacity = $this->capacityForPeriod($assignee, $startsOn, $endsOn);
        $scheduled = 0.0;
        foreach ($assignments as $assignment) {
            $scheduled += $this->assignmentHoursForPeriod($assignment, $startsOn, $endsOn);
        }

        $timeOffHours = 0.0;
        foreach ($timeOff as $entry) {
            $timeOffHours += $this->timeOffHoursForPeriod($entry, $startsOn, $endsOn);
        }

        return [
            'capacity' => round($capacity, 2),
            'scheduled' => round($scheduled, 2),
            'time_off' => round($timeOffHours, 2),
            'availability' => round($capacity - $timeOffHours - $scheduled, 2),
        ];
    }

    /**
     * @param  array<int, int>  $workDays
     */
    public function workingDaysBetween(CarbonInterface|string $startsOn, CarbonInterface|string $endsOn, array $workDays): int
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();
        if ($end->lt($start)) {
            return 0;
        }

        $count = 0;
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $workDays, true)) {
                $count++;
            }
        }

        return $count;
    }

    public function calendarDaysBetween(CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): int
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();

        return $end->lt($start) ? 0 : $start->diffInDays($end) + 1;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function overlap(
        CarbonInterface|string $aStart,
        CarbonInterface|string $aEnd,
        CarbonInterface|string $bStart,
        CarbonInterface|string $bEnd,
    ): ?array {
        $start = CarbonImmutable::parse($aStart)->max(CarbonImmutable::parse($bStart));
        $end = CarbonImmutable::parse($aEnd)->min(CarbonImmutable::parse($bEnd));

        return $end->lt($start) ? null : [$start, $end];
    }
}
