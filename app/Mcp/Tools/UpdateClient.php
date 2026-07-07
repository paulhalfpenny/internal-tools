<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a client. This is an admin-only standard write and does not require web approval.')]
class UpdateClient extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()
                ->description('Internal Tools client ID to update.')
                ->required(),
            'name' => $schema->string()
                ->description('Optional replacement client name.')
                ->max(255),
            'code' => $schema->string()
                ->description('Optional replacement unique client code, up to 20 characters. Send null to clear it.')
                ->max(20)
                ->nullable(),
            'task_billability_profile' => $schema->string()
                ->description('Optional replacement task billability defaults profile: agency or jdw.')
                ->enum(['agency', 'jdw']),
            'default_task_ids' => $schema->array()
                ->description('Optional replacement list of default Internal Tools task IDs for this client.')
                ->items($schema->integer()),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'task_billability_profile' => ['sometimes', 'string', 'in:agency,jdw'],
            'default_task_ids' => ['sometimes', 'array'],
            'default_task_ids.*' => ['integer', 'exists:tasks,id'],
        ]);

        $client = Client::findOrFail((int) $input['client_id']);
        $client = $actions->updateClient($user, $client, Arr::except($input, ['client_id']));
        $result = ['client_id' => $client->id];

        $this->auditCompleted($audit, $user, 'update_client', $input, ['approval_required' => false, ...$result], $client);

        return $this->ok($result);
    }
}
