<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Client;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a client. This is an admin-only standard write and does not require web approval.')]
class UpdateClient extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
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
