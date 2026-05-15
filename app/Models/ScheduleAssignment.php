<?php

namespace App\Models;

use Database\Factories\ScheduleAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property int|null $schedule_placeholder_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property float $hours_per_day
 * @property string|null $notes
 * @property Project $project
 * @property User|null $user
 * @property SchedulePlaceholder|null $placeholder
 */
class ScheduleAssignment extends Model
{
    /** @use HasFactory<ScheduleAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'schedule_placeholder_id',
        'starts_on',
        'ends_on',
        'hours_per_day',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'hours_per_day' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SchedulePlaceholder, $this> */
    public function placeholder(): BelongsTo
    {
        return $this->belongsTo(SchedulePlaceholder::class, 'schedule_placeholder_id');
    }

    public function assigneeType(): string
    {
        return $this->schedule_placeholder_id !== null ? 'placeholder' : 'user';
    }

    public function assigneeId(): ?int
    {
        return $this->schedule_placeholder_id ?? $this->user_id;
    }

    public function assigneeName(): string
    {
        if ($this->schedule_placeholder_id !== null) {
            return $this->placeholder?->name ?? 'Placeholder';
        }

        return $this->user?->name ?? 'Unassigned user';
    }

    public function assigneeRoleTitle(): ?string
    {
        if ($this->schedule_placeholder_id !== null) {
            return $this->placeholder?->role_title;
        }

        return $this->user?->role_title;
    }
}
