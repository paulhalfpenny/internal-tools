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
#[Description('List users. This is admin-only because it exposes team directory data.')]
class ListUsers extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_inactive' => $schema->boolean()
                ->description('Whether admins should include inactive users. Defaults to false.'),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->validate(['include_inactive' => ['sometimes', 'boolean']]);

        return Response::structured([
            'users' => array_map(
                fn ($teamUser): array => $actions->serializeUser($teamUser),
                $actions->listUsers($user, (bool) ($input['include_inactive'] ?? false)),
            ),
        ]);
    }
}
