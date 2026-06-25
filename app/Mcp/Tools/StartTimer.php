<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Start a timer for the authenticated user. This is a standard write and does not require web approval.')]
class StartTimer extends Tool
{
    use InteractsWithInternalTools;

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
