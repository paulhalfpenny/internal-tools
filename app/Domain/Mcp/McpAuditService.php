<?php

namespace App\Domain\Mcp;

use App\Models\McpAuditLog;
use App\Models\McpPendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class McpAuditService
{
    private const SENSITIVE_KEYS = [
        'access_token',
        'body',
        'client_secret',
        'comment',
        'description',
        'google_access_token',
        'google_refresh_token',
        'notes',
        'password',
        'refresh_token',
        'secret',
        'token',
    ];

    /**
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $result
     */
    public function record(
        User $user,
        string $toolName,
        string $action,
        string $status,
        ?array $input = null,
        ?array $result = null,
        ?Model $subject = null,
        string $riskLevel = 'standard',
        ?McpPendingAction $pendingAction = null,
    ): McpAuditLog {
        return McpAuditLog::create([
            'user_id' => $user->id,
            'pending_action_id' => $pendingAction?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'tool_name' => $toolName,
            'action' => $action,
            'risk_level' => $riskLevel,
            'status' => $status,
            'input' => $this->preparePayloadForStorage($input),
            'result' => $this->preparePayloadForStorage($result),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function preparePayloadForStorage(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return [
            '_hash' => McpPayloadHasher::hash($payload),
            'data' => $this->redact($payload),
        ];
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return '[redacted]';
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $itemKey => $itemValue) {
            $redacted[$itemKey] = $this->redact($itemValue, is_string($itemKey) ? $itemKey : null);
        }

        return $redacted;
    }
}
