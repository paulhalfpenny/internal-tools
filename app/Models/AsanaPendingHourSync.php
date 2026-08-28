<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $asana_task_gid
 * @property int $project_id
 * @property string $reason
 */
class AsanaPendingHourSync extends Model
{
    protected $fillable = ['asana_task_gid', 'project_id', 'reason'];
}
