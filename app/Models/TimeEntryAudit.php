<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $time_entry_id
 * @property int $changed_by_user_id
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property Carbon $created_at
 */
class TimeEntryAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'time_entry_id', 'changed_by_user_id', 'field', 'old_value', 'new_value',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TimeEntry, $this> */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
