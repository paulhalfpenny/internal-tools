<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\McpApprovalService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Request deletion of a time entry. All time-entry deletes are high-impact and require web approval before execution.')]
class DeleteTimeEntry extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'time_entry_id' => $schema->integer()
                ->description('Internal Tools time entry ID to delete. Deletion always requires web approval before execution.')
                ->required(),
        ];
    }

    public function handle(Request $request, McpApprovalService $approvals)
    {
        $user = $this->user($request);
        $input = $request->validate([
            'time_entry_id' => ['required', 'integer', 'exists:time_entries,id'],
        ]);

        $entry = TimeEntry::findOrFail((int) $input['time_entry_id']);
        if ($entry->user_id !== $user->id && ! $user->isAdmin()) {
            throw new AuthorizationException('Only admins can request deletion of another user\'s time entry.');
        }

        return Response::structured($approvals->request(
            actor: $user,
            toolName: $this->name(),
            action: 'delete_time_entry',
            payload: ['time_entry_id' => $entry->id],
            input: $input,
            subject: $entry,
        ));
    }
}
