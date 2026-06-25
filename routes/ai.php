<?php

use App\Mcp\InternalToolsServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Mcp::oauthRoutes();

Mcp::web('/mcp', InternalToolsServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use')]);
