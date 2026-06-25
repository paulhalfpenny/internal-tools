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
#[Description('List time entries for the authenticated user, or for an allowed team member/admin scope.')]
class ListTimeEntries extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions)
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
