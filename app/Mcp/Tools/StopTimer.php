<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Stop the authenticated user\'s running timer. This is a standard write and does not require web approval.')]
class StopTimer extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit): ResponseFactory
    {
        $user = $this->user($request);
        $entry = $actions->stopTimer($user);
        $result = ['time_entry' => $actions->serializeTimeEntry($entry)];

        $this->auditCompleted($audit, $user, 'stop_timer', [], ['approval_required' => false, ...$result], $entry);

        return $this->ok($result);
    }
}
