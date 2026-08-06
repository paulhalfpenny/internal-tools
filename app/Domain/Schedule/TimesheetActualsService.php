<?php

namespace App\Domain\Schedule;

use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class TimesheetActualsService
{
    private const LIFETIME_CACHE_VERSION_KEY = 'schedule:lifetime-actuals:version';

    /**
     * @param  array<int, int>  $projectIds
     * @param  array<int, array{index: int, starts_on: string, ends_on: string}>  $periods
     * @return array<int, array<int, float>> [projectId => [periodIndex => hours]]
     */
    public function actualsByProjectForPeriods(array $projectIds, array $periods): array
    {
        if ($projectIds === [] || $periods === []) {
            return [];
        }

        [$rangeStart, $rangeEnd] = $this->rangeBounds($periods);

        $rows = TimeEntry::query()
            ->whereIn('project_id', $projectIds)
            ->whereBetween('spent_on', [$rangeStart, $rangeEnd])
            ->selectRaw('project_id, spent_on, SUM(hours) as total_hours')
            ->groupBy('project_id', 'spent_on')
            ->get(['project_id', 'spent_on'])
            ->map(fn (TimeEntry $row) => [
                'project_id' => (int) $row->project_id,
                'spent_on' => $row->spent_on,
                'total_hours' => (float) $row->getAttribute('total_hours'),
            ]);

        $result = [];
        foreach ($rows as $row) {
            $periodIndex = $this->findPeriodIndex($periods, $row['spent_on']);
            if ($periodIndex === null) {
                continue;
            }
            $projectId = $row['project_id'];
            $result[$projectId][$periodIndex] = ($result[$projectId][$periodIndex] ?? 0.0) + $row['total_hours'];
        }

        foreach ($result as $projectId => $periodMap) {
            foreach ($periodMap as $periodIndex => $hours) {
                $result[$projectId][$periodIndex] = round($hours, 2);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, float> [projectId => hours]
     */
    public function lifetimeActualsByProject(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));
        sort($projectIds);

        if ($projectIds === []) {
            return [];
        }

        Cache::add(self::LIFETIME_CACHE_VERSION_KEY, 1);
        $version = (int) Cache::get(self::LIFETIME_CACHE_VERSION_KEY, 1);
        $cacheKey = 'schedule:lifetime-actuals:'.$version.':'.implode(',', $projectIds);

        /** @var array<int, float> $actuals */
        $actuals = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($projectIds): array {
            $actuals = TimeEntry::query()
                ->whereIn('project_id', $projectIds)
                ->selectRaw('project_id, SUM(hours) as total_hours')
                ->groupBy('project_id')
                ->pluck('total_hours', 'project_id')
                ->map(fn ($hours) => round((float) $hours, 2))
                ->all();

            ksort($actuals);

            return $actuals;
        });

        return $actuals;
    }

    public function invalidateLifetimeActuals(): void
    {
        Cache::add(self::LIFETIME_CACHE_VERSION_KEY, 1);
        Cache::increment(self::LIFETIME_CACHE_VERSION_KEY);
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, array{index: int, starts_on: string, ends_on: string}>  $periods
     * @return array<int, array<int, float>> [userId => [periodIndex => hours]]
     */
    public function actualsByUserForPeriods(array $userIds, array $periods): array
    {
        if ($userIds === [] || $periods === []) {
            return [];
        }

        [$rangeStart, $rangeEnd] = $this->rangeBounds($periods);

        $rows = TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('spent_on', [$rangeStart, $rangeEnd])
            ->selectRaw('user_id, spent_on, SUM(hours) as total_hours')
            ->groupBy('user_id', 'spent_on')
            ->get(['user_id', 'spent_on'])
            ->map(fn (TimeEntry $row) => [
                'user_id' => (int) $row->user_id,
                'spent_on' => $row->spent_on,
                'total_hours' => (float) $row->getAttribute('total_hours'),
            ]);

        $result = [];
        foreach ($rows as $row) {
            $periodIndex = $this->findPeriodIndex($periods, $row['spent_on']);
            if ($periodIndex === null) {
                continue;
            }
            $userId = $row['user_id'];
            $result[$userId][$periodIndex] = ($result[$userId][$periodIndex] ?? 0.0) + $row['total_hours'];
        }

        foreach ($result as $userId => $periodMap) {
            foreach ($periodMap as $periodIndex => $hours) {
                $result[$userId][$periodIndex] = round($hours, 2);
            }
        }

        return $result;
    }

    /**
     * @param  non-empty-array<int, array{starts_on: string, ends_on: string}>  $periods
     * @return array{0: string, 1: string}
     */
    private function rangeBounds(array $periods): array
    {
        $starts = array_column($periods, 'starts_on');
        $ends = array_column($periods, 'ends_on');
        sort($starts);
        sort($ends);

        return [$starts[0], $ends[count($ends) - 1]];
    }

    /**
     * @param  array<int, array{index: int, starts_on: string, ends_on: string}>  $periods
     */
    private function findPeriodIndex(array $periods, mixed $spentOn): ?int
    {
        $date = CarbonImmutable::parse((string) $spentOn)->toDateString();

        foreach ($periods as $period) {
            if ($date >= $period['starts_on'] && $date <= $period['ends_on']) {
                return (int) $period['index'];
            }
        }

        return null;
    }
}
