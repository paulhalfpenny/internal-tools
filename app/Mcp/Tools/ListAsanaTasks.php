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
#[Description('List cached Asana tasks from the Asana board or boards linked to an Internal Tools project.')]
class ListAsanaTasks extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Internal Tools project ID whose linked Asana board tasks should be listed.')
                ->required(),
            'asana_project_gid' => $schema->string()
                ->description('Optional Asana board GID to narrow results when the project links to multiple Asana boards.'),
            'include_completed' => $schema->boolean()
                ->description('Whether to include completed Asana tasks. Defaults to false.'),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'asana_project_gid' => ['sometimes', 'string'],
            'include_completed' => ['sometimes', 'boolean'],
        ]);

        $result = $actions->listAsanaTasks(
            $user,
            (int) $input['project_id'],
            $input['asana_project_gid'] ?? null,
            (bool) ($input['include_completed'] ?? false),
        );

        return Response::structured([
            'project_id' => (int) $input['project_id'],
            'asana_project_gids' => $result['asana_project_gids'],
            'asana_tasks' => array_map(
                fn ($task): array => $actions->serializeAsanaTask($task),
                $result['tasks'],
            ),
        ]);
    }
}
