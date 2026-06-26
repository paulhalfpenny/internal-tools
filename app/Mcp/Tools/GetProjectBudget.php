<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Return budget status for a project, if that project has budget settings.')]
class GetProjectBudget extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Internal Tools project ID whose budget status should be returned.')
                ->required(),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions)
    {
        $user = $this->user($request);
        $input = $request->validate(['project_id' => ['required', 'integer', 'exists:projects,id']]);
        $project = Project::findOrFail((int) $input['project_id']);

        return Response::structured([
            'project_id' => $project->id,
            'budget' => $actions->projectBudget($user, $project),
        ]);
    }
}
