<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpApprovalService;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a time entry. Updating another user\'s entry is high-impact and returns an approval_url instead of mutating immediately.')]
class UpdateTimeEntry extends Tool
{
    use InteractsWithInternalTools;

    public function handle(
        Request $request,
        InternalMcpActions $actions,
        McpAuditService $audit,
        McpApprovalService $approvals,
    ) {
        $user = $this->user($request);
        $input = $request->validate([
            'time_entry_id' => ['required', 'integer', 'exists:time_entries,id'],
            'project_id' => ['sometimes', 'integer', 'exists:projects,id'],
            'task_id' => ['sometimes', 'integer', 'exists:tasks,id'],
            'spent_on' => ['sometimes', 'date_format:Y-m-d'],
            'hours' => ['sometimes'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'asana_task_gid' => ['nullable', 'string'],
        ]);

        $entry = TimeEntry::findOrFail((int) $input['time_entry_id']);
        $data = Arr::except($input, ['time_entry_id']);

        if ($entry->user_id !== $user->id) {
            if (! $user->isAdmin()) {
                throw new AuthorizationException('Only admins can request changes to another user\'s time entry.');
            }

            return Response::structured($approvals->request(
                actor: $user,
                toolName: $this->name(),
                action: 'update_time_entry',
                payload: [
                    'time_entry_id' => $entry->id,
                    'data' => $data,
                ],
                input: $input,
                subject: $entry,
            ));
        }

        $updated = $actions->updateTimeEntry($user, $entry, $data);
        $result = ['time_entry_id' => $updated->id];

        $this->auditCompleted($audit, $user, 'update_time_entry', $input, ['approval_required' => false, ...$result], $updated);

        return $this->ok($result);
    }
}
