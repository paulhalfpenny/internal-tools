<?php

namespace App\Domain\Mcp;

use App\Models\McpAuditLog;
use App\Models\McpPendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class McpAuditService
{
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
            'input' => $input,
            'result' => $result,
            'created_at' => now(),
        ]);
    }
}
