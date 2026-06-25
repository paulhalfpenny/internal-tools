<?php

use App\Mcp\InternalToolsServer;
use App\Mcp\Tools\AssignProjectMember;
use App\Mcp\Tools\CreateClient;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\DeleteTimeEntry;
use App\Mcp\Tools\LogTimeEntry;
use App\Mcp\Tools\UpdateTimeEntry;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\McpPendingAction;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function mcpAssignedProject(User $user): array
{
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Website rebuild']);
    $task = Task::factory()->create(['name' => 'Development']);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    return [$client, $project, $task];
}

test('oauth discovery exposes the MCP scope and existing API tokens still work', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use']);

    $user = User::factory()->create();
    $token = PersonalAccessToken::generate($user, 'Freshdesk widget')['token'];

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('oauth dynamic registration allows trusted AI connector redirect origins by default', function () {
    expect(config('mcp.redirect_domains'))
        ->toContain('https://claude.ai')
        ->toContain('https://chatgpt.com');

    foreach ([
        'https://claude.ai/api/mcp/auth_callback',
        'https://chatgpt.com/connector/oauth/test-callback',
    ] as $redirectUri) {
        $this->postJson('/oauth/register', [
            'client_name' => 'Trusted AI connector',
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => 'mcp:use',
            'token_endpoint_auth_method' => 'none',
        ])
            ->assertCreated()
            ->assertJsonPath('redirect_uris.0', $redirectUri);
    }
});

test('oauth authorize renders a consent screen for registered MCP clients', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';

    $clientId = $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [$redirectUri],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'scope' => 'mcp:use',
        'token_endpoint_auth_method' => 'none',
    ])
        ->assertCreated()
        ->json('client_id');

    $response = $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => 'H4RW8540FmDndrERFfkQHOE_EfPMMHY-30CFd74gnTE',
            'code_challenge_method' => 'S256',
            'state' => 'test-state',
            'scope' => 'mcp:use',
            'resource' => 'https://internal.filter.agency/mcp',
        ]))
        ->assertOk()
        ->assertSee('Claude')
        ->assertSee('Approve');

    preg_match('/name="auth_token" value="([^"]+)"/', $response->getContent(), $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    $approval = $this->post(route('passport.authorizations.approve'), [
        'auth_token' => $matches[1],
    ]);

    $approval->assertRedirect();

    expect($approval->headers->get('Location'))
        ->toStartWith($redirectUri.'?code=')
        ->toContain('state=test-state');
});

test('mcp endpoint requires an OAuth token with the MCP scope', function () {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 'init-1',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'Test Client', 'version' => '1.0.0'],
        ],
    ];

    $this->postJson('/mcp', $payload)->assertUnauthorized();

    Passport::actingAs(User::factory()->create(), []);

    $this->postJson('/mcp', $payload)->assertForbidden();

    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson('/mcp', $payload)
        ->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 'init-1');
});

test('log time entry writes immediately and records an MCP audit row', function () {
    $user = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(LogTimeEntry::class, [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-05-04',
            'hours' => '1:30',
            'notes' => 'Build landing page',
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'time_entry_id' => 1,
        ]);

    $entry = TimeEntry::firstOrFail();
    expect((float) $entry->hours)->toBe(1.5)
        ->and($entry->user_id)->toBe($user->id);

    expect(McpAuditLog::where('action', 'log_time_entry')
        ->where('user_id', $user->id)
        ->where('status', 'completed')
        ->exists())->toBeTrue();
});

test('updating the owners own time entry writes immediately without approval', function () {
    $user = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'notes' => 'Old note',
    ]);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(UpdateTimeEntry::class, [
            'time_entry_id' => $entry->id,
            'hours' => '2:15',
            'notes' => 'Updated by owner',
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'time_entry_id' => $entry->id,
        ]);

    $entry->refresh();
    expect((float) $entry->hours)->toBe(2.25)
        ->and($entry->notes)->toBe('Updated by owner')
        ->and(McpPendingAction::count())->toBe(0);
});

test('updating another users time entry creates a pending approval and does not mutate data', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($owner);
    $entry = TimeEntry::factory()->create([
        'user_id' => $owner->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'hours' => 1.0,
        'notes' => 'Original',
    ]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(UpdateTimeEntry::class, [
            'time_entry_id' => $entry->id,
            'hours' => '3:00',
            'notes' => 'Admin change',
        ])
        ->assertOk()
        ->assertSee('approval_url')
        ->assertSee('approval_required');

    $entry->refresh();
    expect((float) $entry->hours)->toBe(1.0)
        ->and($entry->notes)->toBe('Original')
        ->and(McpPendingAction::where('action', 'update_time_entry')->where('requested_by_user_id', $admin->id)->count())->toBe(1);
});

test('deleting a time entry always requires owner approval and approval executes the delete', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    [, $project, $task] = mcpAssignedProject($user);
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
    ]);

    InternalToolsServer::actingAs($user, 'api')
        ->tool(DeleteTimeEntry::class, ['time_entry_id' => $entry->id])
        ->assertOk()
        ->assertSee('approval_url');

    expect(TimeEntry::whereKey($entry->id)->exists())->toBeTrue();

    $pending = McpPendingAction::firstOrFail();

    $this->actingAs($otherUser)
        ->get(route('mcp.pending-actions.show', $pending->approval_token))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('mcp.pending-actions.approve', $pending->approval_token))
        ->assertRedirect(route('mcp.pending-actions.show', $pending->approval_token));

    expect(TimeEntry::whereKey($entry->id)->exists())->toBeFalse();
    expect($pending->refresh()->status)->toBe('approved');
});

test('admin project and client writes audit immediately without approval', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $task = Task::factory()->create(['name' => 'Strategy']);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(CreateClient::class, [
            'name' => 'Globex',
            'code' => 'GLX',
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'client_id' => 1,
        ]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(CreateProject::class, [
            'client_id' => Client::firstOrFail()->id,
            'code' => 'GLX-001',
            'name' => 'Discovery',
            'task_ids' => [$task->id],
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'project_id' => 1,
        ]);

    InternalToolsServer::actingAs($admin, 'api')
        ->tool(AssignProjectMember::class, [
            'project_id' => Project::firstOrFail()->id,
            'user_id' => $member->id,
        ])
        ->assertOk()
        ->assertStructuredContent([
            'approval_required' => false,
            'project_id' => 1,
            'user_id' => $member->id,
        ]);

    expect(McpPendingAction::count())->toBe(0);
    expect(McpAuditLog::where('action', 'create_client')->where('status', 'completed')->exists())->toBeTrue();
    expect(McpAuditLog::where('action', 'create_project')->where('status', 'completed')->exists())->toBeTrue();
    expect(McpAuditLog::where('action', 'assign_project_member')->where('status', 'completed')->exists())->toBeTrue();
});
