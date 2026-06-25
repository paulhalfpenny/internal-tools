<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a project. This is an admin-only standard write and does not require web approval.')]
class CreateProject extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->all();
        $project = $actions->createProject($user, $input);
        $result = ['project_id' => $project->id];

        $this->auditCompleted($audit, $user, 'create_project', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
