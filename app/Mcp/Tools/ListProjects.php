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
#[Description('List projects visible to the authenticated user. Managers can request all projects.')]
class ListProjects extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions)
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
