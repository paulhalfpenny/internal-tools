<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Remove a user from a project. This is an admin-only standard write and does not require web approval.')]
class UnassignProjectMember extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Internal Tools project ID to remove the user from.')
                ->required(),
            'user_id' => $schema->integer()
                ->description('Internal Tools user ID to remove from the project.')
                ->required(),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $project = Project::findOrFail((int) $input['project_id']);
        $member = User::findOrFail((int) $input['user_id']);
        $actions->unassignProjectMember($user, $project, $member);
        $result = [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ];

        $this->auditCompleted($audit, $user, 'unassign_project_member', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
