<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $gid
 * @property string $workspace_gid
 * @property string $name
 * @property bool $is_archived
 * @property Carbon|null $last_synced_at
 * @property Collection<int, AsanaTask> $tasks
 * @property Collection<int, Project> $projects
 */
class AsanaProject extends Model
{
    protected $primaryKey = 'gid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['gid', 'workspace_gid', 'name', 'is_archived', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<AsanaTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(AsanaTask::class, 'asana_project_gid', 'gid');
    }

    /**
     * Internal projects this Asana board is linked to. The pivot FK is
     * ON DELETE RESTRICT, so a board with links must not be pruned.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'project_asana_links',
            'asana_project_gid',
            'project_id',
            'gid',
            'id',
        );
    }
}
