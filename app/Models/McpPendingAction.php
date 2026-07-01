<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $approval_token
 * @property int $requested_by_user_id
 * @property int|null $approved_by_user_id
 * @property string $tool_name
 * @property string $action
 * @property string $status
 * @property array<string, mixed> $payload
 * @property string|null $payload_hash
 * @property string|null $subject_state_hash
 * @property array<string, mixed>|null $subject_snapshot
 * @property array<string, mixed>|null $result
 * @property Carbon|null $expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property User $requestedBy
 * @property User|null $approvedBy
 */
class McpPendingAction extends Model
{
    protected $fillable = [
        'approval_token',
        'requested_by_user_id',
        'approved_by_user_id',
        'subject_type',
        'subject_id',
        'tool_name',
        'action',
        'status',
        'payload',
        'payload_hash',
        'subject_state_hash',
        'subject_snapshot',
        'result',
        'expires_at',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'subject_snapshot' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<McpAuditLog, $this> */
    public function mcpAuditLogs(): HasMany
    {
        return $this->hasMany(McpAuditLog::class, 'pending_action_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function encryptedEnvelope(array $payload): array
    {
        return [
            '_encrypted' => true,
            'ciphertext' => Crypt::encryptString(json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadData(): array
    {
        return self::decryptEnvelope($this->payload ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function subjectSnapshotData(): ?array
    {
        if ($this->subject_snapshot === null) {
            return null;
        }

        return self::decryptEnvelope($this->subject_snapshot);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function decryptEnvelope(array $payload): array
    {
        if (($payload['_encrypted'] ?? false) !== true || ! is_string($payload['ciphertext'] ?? null)) {
            return $payload;
        }

        $decrypted = json_decode(Crypt::decryptString($payload['ciphertext']), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decrypted) ? $decrypted : [];
    }
}
