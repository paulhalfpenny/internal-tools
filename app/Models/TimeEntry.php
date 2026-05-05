<?php

namespace App\Models;

use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property int $task_id
 * @property Carbon $spent_on
 * @property float $hours
 * @property string|null $notes
 * @property bool $is_running
 * @property Carbon|null $timer_started_at
 * @property bool $is_billable
 * @property float|null $billable_rate_snapshot
 * @property float $billable_amount
 * @property Carbon|null $invoiced_at
 * @property string|null $external_reference
 * @property string|null $asana_task_gid
 * @property Carbon|null $asana_synced_at
 * @property string|null $asana_sync_error
 * @property User $user
 * @property Project $project
 * @property Task $task
 */
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'task_id', 'spent_on', 'hours', 'notes',
        'is_running', 'timer_started_at', 'is_billable', 'billable_rate_snapshot',
        'billable_amount', 'invoiced_at', 'external_reference',
        'asana_task_gid', 'asana_synced_at', 'asana_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'hours' => 'decimal:2',
            'is_running' => 'boolean',
            'timer_started_at' => 'datetime',
            'is_billable' => 'boolean',
            'billable_rate_snapshot' => 'decimal:2',
            'billable_amount' => 'decimal:2',
            'invoiced_at' => 'datetime',
            'asana_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
