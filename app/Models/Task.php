<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_default_billable
 * @property string $colour
 * @property int $sort_order
 * @property bool $is_archived
 * @property Collection<int, Project> $projects
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_default_billable', 'colour', 'sort_order', 'is_archived'];

    protected function casts(): array
    {
        return [
            'is_default_billable' => 'boolean',
            'is_archived' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot(['is_billable', 'hourly_rate_override', 'rate_id']);
    }
}
