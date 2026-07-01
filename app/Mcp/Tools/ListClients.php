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
#[Description('List clients visible to the authenticated user. Managers see all clients; regular users see clients for their projects.')]
class ListClients extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()
                ->description('Whether to include archived clients. Defaults to false.'),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->validate([
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        return Response::structured([
            'clients' => array_map(
                fn ($client): array => $actions->serializeClient($client),
                $actions->listClients($user, (bool) ($input['include_archived'] ?? false)),
            ),
        ]);
    }
}
