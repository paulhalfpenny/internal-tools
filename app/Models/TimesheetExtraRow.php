<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A timesheet row a user has set out for a given week that carries no logged
 * time yet. Rows with time become {@see TimeEntry} records; these placeholder
 * rows are stored here so they survive across days/sessions until the user
 * either logs time against them or removes them.
 *
 * Row keys use the WeekView format "p{projectId}_t{taskId}_a{asanaGid|none}".
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $week_start
 * @property string $row_key
 * @property User $user
 */
class TimesheetExtraRow extends Model
{
    protected $fillable = [
        'user_id', 'week_start', 'row_key',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
