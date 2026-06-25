<?php

namespace App\Domain\Mcp;

use App\Models\Client;
use App\Models\McpPendingAction;
use App\Models\Project;
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
            'payload' => McpPendingAction::encryptedEnvelope($payload),
            'payload_hash' => McpPayloadHasher::hash($payload),
            'subject_state_hash' => $subject !== null ? $this->subjectStateHash($subject) : null,
            'subject_snapshot' => $subject !== null ? McpPendingAction::encryptedEnvelope($this->subjectSnapshot($subject)) : null,
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
        $result = null;
        $validationErrors = [];

        DB::transaction(function () use ($pendingAction, $actor, &$result, &$validationErrors): void {
            $pendingAction = McpPendingAction::whereKey($pendingAction->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($pendingAction, $actor);

            if ($pendingAction->status !== 'pending') {
                $validationErrors = ['action' => 'This MCP action is no longer pending.'];

                return;
            }

            if ($pendingAction->expires_at !== null && $pendingAction->expires_at->isPast()) {
                $this->failPendingAction($pendingAction, 'expired', ['expired' => true]);
                $validationErrors = ['action' => 'This MCP approval URL has expired.'];

                return;
            }

            if ($this->payloadIsInvalid($pendingAction)) {
                $this->failPendingAction($pendingAction, 'invalid', ['invalid' => true]);
                $validationErrors = ['action' => 'This MCP approval payload no longer matches the original request.'];

                return;
            }

            if ($this->subjectIsStale($pendingAction)) {
                $this->failPendingAction($pendingAction, 'stale', ['stale' => true]);
                $validationErrors = ['action' => 'This MCP approval is stale because the target record changed after the request was created.'];

                return;
            }

            $result = match ($pendingAction->action) {
                'update_time_entry' => $this->approveUpdateTimeEntry($pendingAction, $actor),
                'delete_time_entry' => $this->approveDeleteTimeEntry($pendingAction, $actor),
                'archive_client' => $this->approveArchiveClient($pendingAction, $actor),
                'archive_project' => $this->approveArchiveProject($pendingAction, $actor),
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
        });

        if ($validationErrors !== []) {
            throw ValidationException::withMessages($validationErrors);
        }

        if (! is_array($result)) {
            throw ValidationException::withMessages(['action' => 'This MCP action could not be approved.']);
        }

        return $result;
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

    /**
     * @return array<string, mixed>
     */
    public function approvalDetails(McpPendingAction $pendingAction): array
    {
        $payload = $pendingAction->payloadData();

        return [
            'subject' => $pendingAction->subjectSnapshotData(),
            'requested_changes' => $this->requestedChanges($pendingAction->action, $payload),
            'payload' => $payload,
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

    private function payloadIsInvalid(McpPendingAction $pendingAction): bool
    {
        if ($pendingAction->payload_hash === null) {
            return true;
        }

        return ! hash_equals($pendingAction->payload_hash, McpPayloadHasher::hash($pendingAction->payloadData()));
    }

    private function subjectIsStale(McpPendingAction $pendingAction): bool
    {
        if ($pendingAction->subject_state_hash === null) {
            return true;
        }

        $subject = $pendingAction->subject()->first();

        return ! $subject instanceof Model || ! hash_equals($pendingAction->subject_state_hash, $this->subjectStateHash($subject));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function failPendingAction(McpPendingAction $pendingAction, string $status, array $result): void
    {
        $pendingAction->update(['status' => $status]);
        $this->markAudit($pendingAction, $status, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function approveUpdateTimeEntry(McpPendingAction $pendingAction, User $actor): array
    {
        $payload = $pendingAction->payloadData();
        $entry = TimeEntry::findOrFail((int) $payload['time_entry_id']);
        $updated = $this->actions->updateTimeEntry($actor, $entry, $payload['data'] ?? []);

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
        $payload = $pendingAction->payloadData();
        $entry = TimeEntry::findOrFail((int) $payload['time_entry_id']);
        $entryId = $entry->id;

        $this->actions->deleteTimeEntry($actor, $entry);

        return [
            'approval_required' => false,
            'deleted' => true,
            'time_entry_id' => $entryId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveArchiveClient(McpPendingAction $pendingAction, User $actor): array
    {
        $payload = $pendingAction->payloadData();
        $client = Client::findOrFail((int) $payload['client_id']);
        $client = $this->actions->archiveClient($actor, $client, (bool) ($payload['archive'] ?? true));

        return [
            'approval_required' => false,
            'client_id' => $client->id,
            'is_archived' => (bool) $client->is_archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveArchiveProject(McpPendingAction $pendingAction, User $actor): array
    {
        $payload = $pendingAction->payloadData();
        $project = Project::findOrFail((int) $payload['project_id']);
        $project = $this->actions->archiveProject($actor, $project, (bool) ($payload['archive'] ?? true));

        return [
            'approval_required' => false,
            'project_id' => $project->id,
            'is_archived' => (bool) $project->is_archived,
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
                'result' => $this->audit->preparePayloadForStorage($result),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function requestedChanges(string $action, array $payload): array
    {
        return match ($action) {
            'update_time_entry' => $payload['data'] ?? [],
            'archive_client', 'archive_project' => ['archive' => (bool) ($payload['archive'] ?? true)],
            default => $payload,
        };
    }

    private function subjectStateHash(Model $subject): string
    {
        return McpPayloadHasher::hash($this->subjectState($subject));
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectState(Model $subject): array
    {
        return match (true) {
            $subject instanceof TimeEntry => [
                'type' => 'time_entry',
                'id' => $subject->id,
                'user_id' => $subject->user_id,
                'project_id' => $subject->project_id,
                'task_id' => $subject->task_id,
                'spent_on' => $subject->spent_on?->toDateString(),
                'hours' => (string) $subject->hours,
                'notes' => $subject->notes,
                'is_running' => (bool) $subject->is_running,
                'timer_started_at' => $subject->timer_started_at?->toIso8601String(),
                'asana_task_gid' => $subject->asana_task_gid,
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ],
            $subject instanceof Client => [
                'type' => 'client',
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'is_archived' => (bool) $subject->is_archived,
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ],
            $subject instanceof Project => [
                'type' => 'project',
                'id' => $subject->id,
                'client_id' => $subject->client_id,
                'manager_user_id' => $subject->manager_user_id,
                'code' => $subject->code,
                'name' => $subject->name,
                'is_archived' => (bool) $subject->is_archived,
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ],
            default => [
                'type' => $subject->getMorphClass(),
                'id' => $subject->getKey(),
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectSnapshot(Model $subject): array
    {
        if ($subject instanceof TimeEntry) {
            $subject->loadMissing(['project.client', 'task', 'user']);

            return [
                'type' => 'Time entry',
                'id' => $subject->id,
                'user_name' => $subject->user->name,
                'user_email' => $subject->user->email,
                'client_name' => $subject->project->client->name,
                'project_name' => $subject->project->name,
                'task_name' => $subject->task->name,
                'spent_on' => $subject->spent_on?->toDateString(),
                'hours' => (float) $subject->hours,
                'notes' => $subject->notes,
                'asana_task_gid' => $subject->asana_task_gid,
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ];
        }

        if ($subject instanceof Client) {
            return [
                'type' => 'Client',
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'is_archived' => (bool) $subject->is_archived,
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ];
        }

        if ($subject instanceof Project) {
            $subject->loadMissing('client');

            return [
                'type' => 'Project',
                'id' => $subject->id,
                'client_name' => $subject->client->name,
                'code' => $subject->code,
                'name' => $subject->name,
                'is_archived' => (bool) $subject->is_archived,
                'updated_at' => $subject->updated_at?->toIso8601String(),
            ];
        }

        return [
            'type' => $subject->getMorphClass(),
            'id' => $subject->getKey(),
            'updated_at' => $subject->updated_at?->toIso8601String(),
        ];
    }
}
