<?php

namespace App\Domain\Mcp;

use App\Models\McpPendingAction;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class McpApprovalService
{
    public function __construct(
        private readonly InternalMcpActions $actions,
        private readonly McpAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function request(
        User $actor,
        string $toolName,
        string $action,
        array $payload,
        array $input,
        ?Model $subject = null,
    ): array {
        $this->actions->assertActive($actor);

        $pending = McpPendingAction::create([
            'approval_token' => (string) Str::uuid(),
            'requested_by_user_id' => $actor->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'tool_name' => $toolName,
            'action' => $action,
            'status' => 'pending',
            'payload' => $payload,
            'expires_at' => now()->addMinutes((int) config('mcp.pending_action_ttl_minutes', 60)),
        ]);

        $result = $this->approvalResponse($pending);

        $this->audit->record(
            user: $actor,
            toolName: $toolName,
            action: $action,
            status: 'pending_approval',
            input: $input,
            result: $result,
            subject: $subject,
            riskLevel: 'high-impact',
            pendingAction: $pending,
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(McpPendingAction $pendingAction, User $actor): array
    {
        return DB::transaction(function () use ($pendingAction, $actor): array {
            $pendingAction->refresh();
            $this->assertOwner($pendingAction, $actor);
            $this->assertPending($pendingAction);

            $result = match ($pendingAction->action) {
                'update_time_entry' => $this->approveUpdateTimeEntry($pendingAction, $actor),
                'delete_time_entry' => $this->approveDeleteTimeEntry($pendingAction, $actor),
                default => throw ValidationException::withMessages(['action' => 'This pending MCP action cannot be approved.']),
            };

            $pendingAction->update([
                'status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'result' => $result,
            ]);

            $pendingAction->load('requestedBy');
            $this->markAudit($pendingAction, 'approved', $result);

            return $result;
        });
    }

    public function reject(McpPendingAction $pendingAction, User $actor): void
    {
        $this->assertOwner($pendingAction, $actor);
        $this->assertPending($pendingAction);

        $pendingAction->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        $this->markAudit($pendingAction, 'rejected', ['rejected' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function approvalResponse(McpPendingAction $pendingAction): array
    {
        return [
            'approval_required' => true,
            'approval_id' => $pendingAction->approval_token,
            'approval_url' => route('mcp.pending-actions.show', $pendingAction->approval_token),
            'expires_at' => $pendingAction->expires_at?->toIso8601String(),
            'action' => $pendingAction->action,
        ];
    }

    private function assertOwner(McpPendingAction $pendingAction, User $actor): void
    {
        if ($pendingAction->requested_by_user_id !== $actor->id) {
            throw new AuthorizationException('Only the user who requested this MCP action can approve it.');
        }
    }

    private function assertPending(McpPendingAction $pendingAction): void
    {
        if ($pendingAction->status !== 'pending') {
            throw ValidationException::withMessages(['action' => 'This MCP action is no longer pending.']);
        }

        if ($pendingAction->expires_at !== null && $pendingAction->expires_at->isPast()) {
            $pendingAction->update(['status' => 'expired']);
            $this->markAudit($pendingAction, 'expired', ['expired' => true]);

            throw ValidationException::withMessages(['action' => 'This MCP approval URL has expired.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function approveUpdateTimeEntry(McpPendingAction $pendingAction, User $actor): array
    {
        $entry = TimeEntry::findOrFail((int) $pendingAction->payload['time_entry_id']);
        $updated = $this->actions->updateTimeEntry($actor, $entry, $pendingAction->payload['data'] ?? []);

        return [
            'approval_required' => false,
            'time_entry_id' => $updated->id,
            'time_entry' => $this->actions->serializeTimeEntry($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveDeleteTimeEntry(McpPendingAction $pendingAction, User $actor): array
    {
        $entry = TimeEntry::findOrFail((int) $pendingAction->payload['time_entry_id']);
        $entryId = $entry->id;

        $this->actions->deleteTimeEntry($actor, $entry);

        return [
            'approval_required' => false,
            'deleted' => true,
            'time_entry_id' => $entryId,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markAudit(McpPendingAction $pendingAction, string $status, array $result): void
    {
        $pendingAction->loadMissing('requestedBy');

        $pendingAction->mcpAuditLogs->each(function ($auditLog) use ($status, $result): void {
            $auditLog->update([
                'status' => $status,
                'result' => $result,
            ]);
        });
    }
}
