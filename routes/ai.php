<?php

use App\Http\Controllers\Mcp\OAuthRegisterController;
use App\Mcp\InternalToolsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Mcp::ensureMcpScope();

$authorizationServer = static fn (): string => config('mcp.authorization_server') ?? url('/');
$authorizationServerMetadata = static fn (): array => [
    'issuer' => $authorizationServer(),
    'authorization_endpoint' => route('passport.authorizations.authorize'),
    'token_endpoint' => route('passport.token'),
    'registration_endpoint' => url('oauth/register'),
    'response_types_supported' => ['code'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => ['mcp:use'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
];
$protectedResourceMetadata = static fn (string $path = ''): array => [
    'resource' => url('/'.$path),
    'authorization_servers' => [$authorizationServer()],
    'scopes_supported' => ['mcp:use'],
];

Route::get('/.well-known/oauth-protected-resource', static fn () => response()->json($protectedResourceMetadata('')))
    ->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-authorization-server', static fn () => response()->json($authorizationServerMetadata()))
    ->name('mcp.oauth.authorization-server');
Route::get('/.well-known/oauth-protected-resource/{path}', static fn (string $path) => response()->json($protectedResourceMetadata($path)))
    ->where('path', '.*')
    ->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server/{path}', static fn (string $path) => response()->json($authorizationServerMetadata()))
    ->where('path', '.*')
    ->name('mcp.oauth.authorization-server.nested');
Route::post('oauth/register', OAuthRegisterController::class)
    ->name('mcp.oauth.register');

Mcp::web('/mcp', InternalToolsServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use')]);
