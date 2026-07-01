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
#[Description('List time entries for the authenticated user, or for an allowed team member/admin scope.')]
class ListTimeEntries extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->format('date')
                ->description('Optional start date in YYYY-MM-DD format.')
                ->nullable(),
            'to' => $schema->string()
                ->format('date')
                ->description('Optional end date in YYYY-MM-DD format.')
                ->nullable(),
            'user_id' => $schema->integer()
                ->description('Optional user ID. Managers and admins can request other users; regular users are limited to themselves.')
                ->nullable(),
            'project_id' => $schema->integer()
                ->description('Optional project ID filter.')
                ->nullable(),
            'task_id' => $schema->integer()
                ->description('Optional task ID filter.')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of time entries to return, from 1 to 100.')
                ->min(1)
                ->max(100)
                ->nullable(),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        $user = $this->user($request);

        return Response::structured([
            'time_entries' => array_map(
                fn ($entry): array => $actions->serializeTimeEntry($entry),
                $actions->listTimeEntries($user, $request->all()),
            ),
        ]);
    }
}
