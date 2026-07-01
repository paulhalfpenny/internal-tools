<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List projects visible to the authenticated user. Managers and admins can request all projects.')]
class ListProjects extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()
                ->description('Whether to include archived projects. Defaults to false.'),
            'all' => $schema->boolean()
                ->description('Whether managers and admins should return all projects rather than only directly assigned projects. Defaults to false.'),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->validate([
            'include_archived' => ['sometimes', 'boolean'],
            'all' => ['sometimes', 'boolean'],
        ]);

        return Response::structured([
            'projects' => array_map(
                fn ($project): array => $actions->serializeProject($project),
                $actions->listProjects($user, (bool) ($input['include_archived'] ?? false), (bool) ($input['all'] ?? false)),
            ),
        ]);
    }
}
