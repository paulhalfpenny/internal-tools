<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Return the authenticated user\'s currently running timer, if one exists.')]
class GetRunningTimer extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        $user = $this->user($request);
        $entry = $actions->runningTimer($user);

        return Response::structured([
            'running' => $entry === null ? null : $actions->serializeTimeEntry($entry),
        ]);
    }
}
