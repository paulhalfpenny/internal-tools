<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a client. This is an admin-only standard write and does not require web approval.')]
class CreateClient extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->all();
        $client = $actions->createClient($user, $input);
        $result = ['client_id' => $client->id];

        $this->auditCompleted($audit, $user, 'create_client', $input, ['approval_required' => false, ...$result], $client);

        return $this->ok($result);
    }
}
