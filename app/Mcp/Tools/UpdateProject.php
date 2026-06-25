<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Project;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a project. This is an admin-only standard write and does not require web approval.')]
class UpdateProject extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->all();
        $request->validate(['project_id' => ['required', 'integer', 'exists:projects,id']]);

        $project = Project::findOrFail((int) $input['project_id']);
        $project = $actions->updateProject($user, $project, Arr::except($input, ['project_id']));
        $result = ['project_id' => $project->id];

        $this->auditCompleted($audit, $user, 'update_project', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
