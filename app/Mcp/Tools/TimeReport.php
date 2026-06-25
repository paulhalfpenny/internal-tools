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
#[Description('Return time report totals and optional grouped rows for a date range.')]
class TimeReport extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions)
    {
        return Response::structured($actions->timeReport($this->user($request), $request->all()));
    }
}
