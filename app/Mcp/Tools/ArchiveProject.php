<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Archive or unarchive a project. This is an admin-only standard write and does not require web approval.')]
class ArchiveProject extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'archive' => ['sometimes', 'boolean'],
        ]);

        $project = Project::findOrFail((int) $input['project_id']);
        $project = $actions->archiveProject($user, $project, (bool) ($input['archive'] ?? true));
        $result = [
            'project_id' => $project->id,
            'is_archived' => (bool) $project->is_archived,
        ];

        $this->auditCompleted($audit, $user, 'archive_project', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
