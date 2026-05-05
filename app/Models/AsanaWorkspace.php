<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $gid
 * @property string $name
 * @property Carbon|null $last_synced_at
 */
class AsanaWorkspace extends Model
{
    protected $primaryKey = 'gid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['gid', 'name', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
