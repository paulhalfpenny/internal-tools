<?php

namespace App\Http\Controllers\Mcp;

use App\Domain\Mcp\McpApprovalService;
use App\Models\McpPendingAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PendingActionController
{
    public function show(McpPendingAction $pendingAction, McpApprovalService $approvals): View
    {
        $this->authorizeOwner($pendingAction);

        return view('mcp.pending-action', [
            'pendingAction' => $pendingAction,
            'details' => $approvals->approvalDetails($pendingAction),
        ]);
    }

    public function approve(McpPendingAction $pendingAction, McpApprovalService $approvals): RedirectResponse
    {
        $this->authorizeOwner($pendingAction);

        /** @var User $user */
        $user = auth()->user();
        $approvals->approve($pendingAction, $user);

        return redirect()
            ->route('mcp.pending-actions.show', $pendingAction->approval_token)
            ->with('status', 'MCP action approved and executed.');
    }

    public function reject(McpPendingAction $pendingAction, McpApprovalService $approvals): RedirectResponse
    {
        $this->authorizeOwner($pendingAction);

        /** @var User $user */
        $user = auth()->user();
        $approvals->reject($pendingAction, $user);

        return redirect()
            ->route('mcp.pending-actions.show', $pendingAction->approval_token)
            ->with('status', 'MCP action rejected.');
    }

    private function authorizeOwner(McpPendingAction $pendingAction): void
    {
        if ($pendingAction->requested_by_user_id !== auth()->id()) {
            throw new AuthorizationException('Only the user who requested this MCP action can review it.');
        }
    }
}
