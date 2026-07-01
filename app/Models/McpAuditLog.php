<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $pending_action_id
 * @property string $tool_name
 * @property string $action
 * @property string $risk_level
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $result
 * @property Carbon $created_at
 */
class McpAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'pending_action_id',
        'subject_type',
        'subject_id',
        'tool_name',
        'action',
        'risk_level',
        'status',
        'input',
        'result',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<McpPendingAction, $this> */
    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(McpPendingAction::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
