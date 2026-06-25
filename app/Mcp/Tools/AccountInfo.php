<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Return information about the authenticated MCP user and account capabilities.')]
class AccountInfo extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions)
    {
        $user = $this->user($request);
        $actions->assertActive($user);

        return Response::structured([
            'user' => $actions->serializeUser($user),
            'capabilities' => [
                'can_administer' => $user->isAdmin(),
                'can_report' => $user->isManager(),
                'high_impact_writes' => [
                    'update_time_entry_for_another_user',
                    'delete_time_entry',
                ],
            ],
        ]);
    }
}
