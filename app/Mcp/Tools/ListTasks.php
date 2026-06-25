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
#[Description('List available tasks.')]
class ListTasks extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions)
    {
        $user = $this->user($request);
        $input = $request->validate(['include_archived' => ['sometimes', 'boolean']]);

        return Response::structured([
            'tasks' => array_map(
                fn ($task): array => $actions->serializeTask($task),
                $actions->listTasks($user, (bool) ($input['include_archived'] ?? false)),
            ),
        ]);
    }
}
