<?php

namespace App\Mcp\Tools\Concerns;

use App\Domain\Mcp\McpAuditService;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait InteractsWithInternalTools
{
    protected function user(Request $request): User
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ok(array $data)
    {
        return Response::structured([
            'approval_required' => false,
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    protected function auditCompleted(
        McpAuditService $audit,
        User $user,
        string $action,
        array $input,
        array $result,
        ?Model $subject = null,
    ): void {
        $audit->record(
            user: $user,
            toolName: $this->name(),
            action: $action,
            status: 'completed',
            input: $input,
            result: $result,
            subject: $subject,
        );
    }
}
