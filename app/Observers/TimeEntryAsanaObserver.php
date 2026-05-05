<?php

namespace App\Observers;

use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\TimeEntry;

class TimeEntryAsanaObserver
{
    public function saved(TimeEntry $entry): void
    {
        $current = $entry->asana_task_gid;
        $original = $entry->getOriginal('asana_task_gid');

        $taskGids = array_filter(array_unique([$current, $original]));

        foreach ($taskGids as $gid) {
            SyncAsanaTaskHoursJob::dispatch($gid, $entry->project_id);
        }
    }

    public function deleted(TimeEntry $entry): void
    {
        if ($entry->asana_task_gid !== null) {
            SyncAsanaTaskHoursJob::dispatch($entry->asana_task_gid, $entry->project_id);
        }
    }
}
