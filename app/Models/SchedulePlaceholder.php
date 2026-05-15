<?php

namespace App\Models;

use Database\Factories\SchedulePlaceholderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $role_title
 * @property float $weekly_capacity_hours
 * @property array<int, int>|null $schedule_work_days
 * @property Carbon|null $archived_at
 * @property Collection<int, ScheduleAssignment> $scheduleAssignments
 */
class SchedulePlaceholder extends Model
{
    /** @use HasFactory<SchedulePlaceholderFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'role_title',
        'weekly_capacity_hours',
        'schedule_work_days',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'weekly_capacity_hours' => 'decimal:2',
            'schedule_work_days' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<ScheduleAssignment, $this> */
    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    /**
     * ISO-8601 weekdays, Monday=1 through Sunday=7.
     *
     * @return array<int, int>
     */
    public function effectiveScheduleWorkDays(): array
    {
        $days = collect($this->schedule_work_days ?? [1, 2, 3, 4, 5])
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $days !== [] ? $days : [1, 2, 3, 4, 5];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    /**
     * @param  Builder<SchedulePlaceholder>  $query
     * @return Builder<SchedulePlaceholder>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
