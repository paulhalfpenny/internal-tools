<?php

namespace App\Domain\Schedule;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ScheduleShiftService
{
    public function shiftProject(Project $project, CarbonInterface|string $fromDate, CarbonInterface|string $newStartDate): int
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $newStart = CarbonImmutable::parse($newStartDate)->startOfDay();
        $deltaDays = $from->diffInDays($newStart, false);

        if ((int) $deltaDays === 0) {
            return 0;
        }

        return DB::transaction(function () use ($project, $from, $deltaDays) {
            $assignments = $project->scheduleAssignments()
                ->whereDate('starts_on', '>=', $from->toDateString())
                ->lockForUpdate()
                ->get();

            foreach ($assignments as $assignment) {
                $assignment->update([
                    'starts_on' => $assignment->starts_on->addDays((int) $deltaDays)->toDateString(),
                    'ends_on' => $assignment->ends_on->addDays((int) $deltaDays)->toDateString(),
                ]);
            }

            return $assignments->count();
        });
    }
}
