<?php

namespace App\Observers;

use App\Domain\Schedule\TimesheetActualsService;
use App\Models\TimeEntry;

final class TimeEntryScheduleActualsObserver
{
    public function created(TimeEntry $entry): void
    {
        $this->invalidate();
    }

    public function updated(TimeEntry $entry): void
    {
        if ($entry->wasChanged(['project_id', 'hours'])) {
            $this->invalidate();
        }
    }

    public function deleted(TimeEntry $entry): void
    {
        $this->invalidate();
    }

    private function invalidate(): void
    {
        app(TimesheetActualsService::class)->invalidateLifetimeActuals();
    }
}
