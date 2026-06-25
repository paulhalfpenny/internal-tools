<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Client;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Archive or unarchive a client. This is an admin-only standard write and does not require web approval.')]
class ArchiveClient extends Tool
{
    use InteractsWithInternalTools;

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'archive' => ['sometimes', 'boolean'],
        ]);

        $client = Client::findOrFail((int) $input['client_id']);
        $client = $actions->archiveClient($user, $client, (bool) ($input['archive'] ?? true));
        $result = [
            'client_id' => $client->id,
            'is_archived' => (bool) $client->is_archived,
        ];

        $this->auditCompleted($audit, $user, 'archive_client', $input, ['approval_required' => false, ...$result], $client);

        return $this->ok($result);
    }
}
