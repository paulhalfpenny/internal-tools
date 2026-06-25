<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpApprovalService;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Archive or unarchive a project. Archiving is high-impact and returns an approval_url instead of mutating immediately.')]
class ArchiveProject extends Tool
{
    use InteractsWithInternalTools;

    public function handle(
        Request $request,
        InternalMcpActions $actions,
        McpAuditService $audit,
        McpApprovalService $approvals,
    ) {
        $user = $this->user($request);
        $input = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'archive' => ['sometimes', 'boolean'],
        ]);

        $project = Project::findOrFail((int) $input['project_id']);
        $archive = (bool) ($input['archive'] ?? true);
        $actions->assertAdmin($user);

        if ($archive) {
            return Response::structured($approvals->request(
                actor: $user,
                toolName: $this->name(),
                action: 'archive_project',
                payload: [
                    'project_id' => $project->id,
                    'archive' => true,
                ],
                input: $input,
                subject: $project,
            ));
        }

        $project = $actions->archiveProject($user, $project, false);
        $result = [
            'project_id' => $project->id,
            'is_archived' => (bool) $project->is_archived,
        ];

        $this->auditCompleted($audit, $user, 'archive_project', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
