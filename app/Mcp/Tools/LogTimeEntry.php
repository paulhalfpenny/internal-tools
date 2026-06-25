<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Log a time entry for the authenticated user. This is a standard write and does not require web approval.')]
class LogTimeEntry extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Internal Tools project ID to log time against.')
                ->required(),
            'task_id' => $schema->integer()
                ->description('Internal Tools task ID to log time against.')
                ->required(),
            'spent_at' => $schema->string()
                ->format('date-time')
                ->description('Date or ISO-8601 date/time for the time entry. The entry is logged against this date.')
                ->required(),
            'hours' => $schema->string()
                ->description('Hours to log as a string. Accepts decimal hours like "1.5", h:mm like "1:30", or minutes like "90m".')
                ->required(),
            'notes' => $schema->string()
                ->description('Optional notes for the time entry. Omit this field when there are no notes.')
                ->max(2000),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->all();

        if (isset($input['spent_at']) && ! isset($input['spent_on'])) {
            $input['spent_on'] = substr((string) $input['spent_at'], 0, 10);
        }

        $entry = $actions->createTimeEntry($user, $input);
        $result = ['time_entry_id' => $entry->id];

        $this->auditCompleted($audit, $user, 'log_time_entry', $input, ['approval_required' => false, ...$result], $entry);

        return $this->ok($result);
    }
}
