<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpApprovalService;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Client;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Archive or unarchive a client. Archiving is high-impact and returns an approval_url instead of mutating immediately.')]
class ArchiveClient extends Tool
{
    use InteractsWithInternalTools;

    public function handle(
        Request $request,
        InternalMcpActions $actions,
        McpAuditService $audit,
        McpApprovalService $approvals,
    ) {
        $user = $this->user($request);
        $input = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'archive' => ['sometimes', 'boolean'],
        ]);

        $client = Client::findOrFail((int) $input['client_id']);
        $archive = (bool) ($input['archive'] ?? true);
        $actions->assertAdmin($user);

        if ($archive) {
            return Response::structured($approvals->request(
                actor: $user,
                toolName: $this->name(),
                action: 'archive_client',
                payload: [
                    'client_id' => $client->id,
                    'archive' => true,
                ],
                input: $input,
                subject: $client,
            ));
        }

        $client = $actions->archiveClient($user, $client, false);
        $result = [
            'client_id' => $client->id,
            'is_archived' => (bool) $client->is_archived,
        ];

        $this->auditCompleted($audit, $user, 'archive_client', $input, ['approval_required' => false, ...$result], $client);

        return $this->ok($result);
    }
}
