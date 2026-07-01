<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a client. This is an admin-only standard write and does not require web approval.')]
class CreateClient extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Client name.')
                ->max(255)
                ->required(),
            'code' => $schema->string()
                ->description('Optional unique client code, up to 20 characters.')
                ->max(20)
                ->nullable(),
            'default_task_ids' => $schema->array()
                ->description('Optional default Internal Tools task IDs to associate with this client.')
                ->items($schema->integer()),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->all();
        $client = $actions->createClient($user, $input);
        $result = ['client_id' => $client->id];

        $this->auditCompleted($audit, $user, 'create_client', $input, ['approval_required' => false, ...$result], $client);

        return $this->ok($result);
    }
}
