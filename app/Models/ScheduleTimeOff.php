<?php

namespace App\Models;

use Database\Factories\ScheduleTimeOffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property float $hours_per_day
 * @property string $label
 * @property string|null $notes
 * @property User $user
 */
class ScheduleTimeOff extends Model
{
    /** @use HasFactory<ScheduleTimeOffFactory> */
    use HasFactory;

    protected $table = 'schedule_time_off';

    protected $fillable = [
        'user_id',
        'starts_on',
        'ends_on',
        'hours_per_day',
        'label',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
