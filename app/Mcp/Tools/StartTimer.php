<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Start a timer for the authenticated user. This is a standard write and does not require web approval.')]
class StartTimer extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Internal Tools project ID for the timer.')
                ->required(),
            'task_id' => $schema->integer()
                ->description('Internal Tools task ID for the timer.')
                ->required(),
            'spent_on' => $schema->string()
                ->format('date')
                ->description('Timer date in YYYY-MM-DD format.')
                ->required(),
            'notes' => $schema->string()
                ->description('Optional timer notes. Omit this field when there are no notes.')
                ->max(2000)
                ->nullable(),
            'asana_task_gid' => $schema->string()
                ->description('Optional Asana task GID. Required when the project has linked Asana boards and requires Asana tasks.')
                ->nullable(),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->all();
        $entry = $actions->startTimer($user, $input);
        $result = ['time_entry' => $actions->serializeTimeEntry($entry)];

        $this->auditCompleted($audit, $user, 'start_timer', $input, ['approval_required' => false, ...$result], $entry);

        return $this->ok($result);
    }
}
