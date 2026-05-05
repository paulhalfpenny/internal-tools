<?php

namespace App\Models;

use App\Enums\BudgetType;
use App\Enums\JdwCategory;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string $code
 * @property string $name
 * @property bool $is_billable
 * @property float|null $default_hourly_rate
 * @property BudgetType|null $budget_type
 * @property float|null $budget_amount
 * @property float|null $budget_hours
 * @property Carbon|null $budget_starts_on
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_archived
 * @property JdwCategory|null $jdw_category
 * @property int|null $jdw_sort_order
 * @property string|null $jdw_status
 * @property string|null $jdw_estimated_launch
 * @property string|null $jdw_description
 * @property Client $client
 * @property Collection<int, Task> $tasks
 * @property Collection<int, User> $users
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id', 'code', 'name', 'is_billable', 'default_hourly_rate',
        'budget_type', 'budget_amount', 'budget_hours', 'budget_starts_on',
        'starts_on', 'ends_on', 'is_archived',
        'jdw_category', 'jdw_sort_order', 'jdw_status', 'jdw_estimated_launch', 'jdw_description',
    ];

    protected function casts(): array
    {
        return [
            'is_billable' => 'boolean',
            'budget_type' => BudgetType::class,
            'jdw_category' => JdwCategory::class,
            'default_hourly_rate' => 'decimal:2',
            'budget_amount' => 'decimal:2',
            'budget_hours' => 'decimal:2',
            'budget_starts_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_archived' => 'boolean',
            'jdw_sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsToMany<Task, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)
            ->withPivot(['is_billable', 'hourly_rate_override']);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['hourly_rate_override']);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
