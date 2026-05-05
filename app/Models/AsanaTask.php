<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $gid
 * @property string $asana_project_gid
 * @property string $name
 * @property bool $is_completed
 * @property string|null $parent_gid
 * @property Carbon|null $last_synced_at
 * @property AsanaProject $project
 */
class AsanaTask extends Model
{
    protected $primaryKey = 'gid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['gid', 'asana_project_gid', 'name', 'is_completed', 'parent_gid', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AsanaProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AsanaProject::class, 'asana_project_gid', 'gid');
    }
}
