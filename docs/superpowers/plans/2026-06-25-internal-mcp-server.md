# Internal MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an authenticated MCP server to the Internal Tools Laravel app so AI clients can track time, review time entries, report on time and budgets, and manage clients, projects, tasks, and project assignments.

**Architecture:** Use Laravel MCP as a thin HTTP tool layer over the existing Laravel domain model. Keep business rules in first-party services under `app/Domain`, then expose those services via small MCP tool classes under `app/Mcp/Tools`. Protect the MCP route with Laravel Passport OAuth 2.1 so users can connect from MCP clients with a normal browser authorization flow instead of copying tokens into client config.

**Tech Stack:** Laravel 11.51, PHP 8.2, `laravel/mcp:^0.8`, `laravel/passport:^13.7`, OAuth 2.1, Pest

## Global Constraints

- Do not commit during implementation unless the user explicitly approves a commit in that task.
- The MCP endpoint must never be unauthenticated.
- MCP tools must not silently guess when a project, task, client, user, or time entry lookup is ambiguous; return candidate matches instead.
- Non-admin users may only act on their own time entries and projects they are assigned to.
- Managers may read reports and log/edit time for direct reports only; admins may act across active users.
- Admin-only mutations: create/update/archive projects, create/update/archive clients, create/update/archive account tasks, and assign/unassign project teammates.
- Preserve existing API behavior in `routes/api.php`; all current API tests must keep passing.
- MCP setup must use OAuth 2.1 via Passport as the primary path for users and clients.
- Do not remove the existing `PersonalAccessToken` model or `/api/*` bearer-token endpoints; they remain for the current non-MCP API surfaces.
- MCP write tools must be guarded against accidental or rogue-client mutation. High-impact mutations require a preview response first, then explicit user approval inside the Internal Tools web UI before the MCP client can execute the mutation.
- High-impact MCP mutations must require explicit stable IDs on execution. Name/search resolution is allowed for previews, but the approved execution payload must include the resolved IDs that were shown in the preview.
- Every successful MCP write must create an audit row with actor, tool name, action type, target type/id, payload hash, and whether web approval was required.

---

## Ticket And Reference Summary

Asana task: `1215914556569880` / "Add MCP Server".

Requested capabilities:
- Start, stop, and inspect timers.
- Log, edit, delete, and list time entries.
- Review time entries by date range and project.
- Log time on behalf of a teammate when permitted.
- Create, update, archive, browse, and search projects.
- Create, update, browse, and search clients.
- See team members.
- Read project assignments and billable rates.
- Assign and unassign teammates on projects.
- Check account/server capabilities before acting.

Harvest MCP reference adds useful expectations:
- Time reports should support date range grouping by project, client, or person.
- Project budget checks should state whether the budget is monthly or lifetime.
- Account capability checks should help clients validate a request before mutating data.

Laravel MCP reference points used:
- Install with `composer require laravel/mcp`.
- Install Passport with `composer require laravel/passport` and `php artisan passport:install`.
- Publish `routes/ai.php` with `php artisan vendor:publish --tag=ai-routes`.
- Register HTTP MCP servers with `Mcp::web('/mcp/...', Server::class)`.
- Register OAuth discovery and dynamic-client-registration routes with `Mcp::oauthRoutes()`.
- Protect the MCP route with Passport's `auth:api` middleware.
- Configure Passport's authorization view with `Passport::authorizationView(...)`.
- Unit-test tools through `Server::tool(ToolClass::class, [...])`.

Composer dry-run result:
- `composer require laravel/mcp:^0.8 --dry-run` resolves cleanly in this repo and installs `laravel/mcp v0.8.1` plus `illuminate/json-schema v12.62.0`.
- `composer require laravel/passport --dry-run` resolves cleanly in this repo and installs `laravel/passport v13.7.5` plus its OAuth server dependencies.
- `composer require php-mcp/laravel:^4.0 --dry-run` fails without `-W` because `phpdocumentor/reflection-docblock` is locked to an incompatible major. Do not choose that package for this plan.

---

## Tool Catalog

All tool responses should be JSON. Tool names use kebab case because Laravel MCP derives useful names from class names but explicit names are clearer for clients.

| Tool | Permission | Required Inputs | Optional Inputs | Output |
| --- | --- | --- | --- | --- |
| `get-capabilities` | authenticated OAuth user | none | none | server settings, current user role, allowed actions |
| `search-projects` | assigned projects for users; all active/archived for admins | none | `query`, `client`, `status` (`active`, `archived`, `all`) | project rows with client, code, tasks, assignment summary |
| `search-clients` | managers/admins | none | `query`, `status` | client rows |
| `search-tasks` | authenticated OAuth user | none | `query`, `status`, `project_id`, `project` | task rows |
| `search-team-members` | managers/admins | none | `query`, `status` | user rows |
| `start-timer` | own assigned projects | `project` or `project_id`; `task` or `task_id` | `spent_on`, `notes`, `asana_task_gid` | created running time entry |
| `stop-timer` | own running timer | none | none | stopped time entry |
| `get-running-timer` | own timer | none | none | running entry or null |
| `log-time-entry` | own entries; direct reports for managers; all users for admins | `project` or `project_id`; `task` or `task_id`; `spent_on`; `hours` | `notes`, `user` or `user_id`, `asana_task_gid` | created entry |
| `update-time-entry` | owner/direct-report/admin | `entry_id` | `project`, `project_id`, `task`, `task_id`, `spent_on`, `hours`, `notes`, `asana_task_gid` | updated entry |
| `delete-time-entry` | owner/direct-report/admin | `entry_id` | none | deleted id |
| `list-time-entries` | own; direct reports for managers; all users for admins | `from`, `to` | `project`, `project_id`, `client`, `client_id`, `user`, `user_id` | entries plus totals |
| `time-report` | managers/admins; own summary for users | `from`, `to`, `group_by` | `project`, `project_id`, `client`, `client_id`, `user`, `user_id`, `billable_only` | grouped totals |
| `project-budget-status` | managers/admins | `project` or `project_id` | `as_of` | budget type, scope, used hours/amount, remaining |
| `create-client` | admin | `name` | `code` | client row |
| `update-client` | admin | `client` or `client_id` | `name`, `code`, `is_archived` | client row |
| `create-project` | admin | `client` or `client_id`; `name` | `code`, `is_billable`, `default_hourly_rate`, `budget_type`, `budget_amount`, `budget_hours`, `budget_starts_on`, `task_ids`, `user_ids` | project row |
| `update-project` | admin | `project` or `project_id` | same editable project fields as admin screen | project row |
| `archive-project` | admin | `project` or `project_id` | `archived` default true | project row |
| `create-task` | admin | `name` | `is_default_billable`, `colour` | task row |
| `update-task` | admin | `task` or `task_id` | `name`, `is_default_billable`, `colour`, `is_archived` | task row |
| `list-project-assignments` | managers/admins | `project` or `project_id` | none | assigned users and rates |
| `assign-project-member` | admin | `project` or `project_id`; `user` or `user_id` | `hourly_rate_override` | assignment row |
| `unassign-project-member` | admin | `project` or `project_id`; `user` or `user_id` | none | removed assignment |

## Mutation Guardrails

All MCP tools fall into one of three safety classes:

| Safety Class | Tools | Guardrail |
| --- | --- | --- |
| Read-only | `get-capabilities`, searches, lists, reports, budget checks, assignment reads | Auth + authorization only |
| Standard writes | `start-timer`, `stop-timer`, `log-time-entry`, `update-time-entry` for the authenticated user's own entries, all client/project/task create/update/archive tools, `assign-project-member`, `unassign-project-member` | Auth + authorization + audit log |
| High-impact writes | `update-time-entry` when changing another user's entry, `delete-time-entry`, and future `delete-project` / `delete-client` tools if actual deletion is added later | Auth + authorization + explicit IDs + server-side web approval + audit log |

High-impact tool flow:
1. First call with the intended inputs and no approved `approval_id` creates an `mcp_pending_actions` row and returns `requires_approval: true`, `approval_id`, `approval_url`, `expires_at`, `summary`, `resolved_ids`, and `impact`.
2. The user must open `approval_url` in the Internal Tools web app, review the resolved IDs and impact, and approve or reject the pending action. The approval page requires normal web authentication and only the pending action owner may approve.
3. The client may call the same tool again with `confirm: true`, `approval_id`, and the explicit IDs returned in `resolved_ids`.
4. `McpMutationGuard` rejects execution if the pending action is missing, expired, rejected, not approved by the actor, created for a different actor/tool/action/payload, or created for stale entity state.
5. An approved tool response must include `approved: true`, `approval_id`, the exact target ids changed, and a before/after summary for update/archive/assignment changes.

The v1 tool catalog does not include `delete-project` or `delete-client`; projects and clients are archived instead. If true deletion tools are added later, they must be high-impact writes.

---

## File Structure

Create:
- `routes/ai.php` - MCP server route registration.
- `app/Mcp/Servers/InternalToolsServer.php` - server metadata and tool registration.
- `app/Mcp/Tools/*Tool.php` - one Laravel MCP tool class per tool in the catalog.
- `app/Mcp/Support/McpToolResponse.php` - small helper for JSON success/error payloads.
- `resources/views/mcp/authorize.blade.php` - Passport authorization screen presented during MCP OAuth consent.
- `app/Domain/Mcp/EntityResolver.php` - canonical lookup and ambiguity handling for client/project/task/user names and IDs.
- `app/Domain/Mcp/McpAuthorization.php` - centralized permission checks for current user vs target user/project/entry/admin action.
- `app/Domain/Mcp/McpMutationGuard.php` - pending-action preview generation, approval verification, explicit-ID enforcement, payload hashing, and write audit helper.
- `app/Domain/Mcp/SerializesMcpModels.php` - shared serializers for clients, projects, tasks, users, assignments, time entries, budgets, and report rows.
- `app/Domain/TimeTracking/TimeEntryGuard.php` - reusable project/task/asana validation currently duplicated by API controllers.
- `app/Domain/Projects/ProjectCommandService.php` - create/update/archive projects and sync tasks/users without Livewire state.
- `app/Domain/Clients/ClientCommandService.php` - create/update/archive clients.
- `app/Domain/Tasks/TaskCommandService.php` - create/update/archive account tasks.
- `app/Domain/Reporting/McpTimeReportService.php` - MCP-friendly wrappers over `TimeReportQuery` and `ProjectBudgetCalculator`.
- `app/Http/Controllers/McpPendingActionController.php` - web UI approval/rejection flow for high-impact MCP actions.
- `app/Models/McpAuditLog.php` - audit model for MCP writes.
- `app/Models/McpPendingAction.php` - pending high-impact MCP action model.
- `database/migrations/2026_06_25_120000_create_mcp_audit_logs_table.php` - write audit table.
- `database/migrations/2026_06_25_120100_create_mcp_pending_actions_table.php` - pending approval table.
- `resources/views/mcp/pending-action.blade.php` - approval/rejection UI for high-impact MCP actions.
- `tests/Feature/Mcp/McpServerAuthTest.php`
- `tests/Feature/Mcp/McpEntityResolverTest.php`
- `tests/Feature/Mcp/McpMutationGuardTest.php`
- `tests/Feature/Mcp/McpPendingActionApprovalTest.php`
- `tests/Feature/Mcp/McpTimeTrackingToolsTest.php`
- `tests/Feature/Mcp/McpReportingToolsTest.php`
- `tests/Feature/Mcp/McpAdminToolsTest.php`
- `docs/MCP.md`

Modify:
- `composer.json` and `composer.lock` - add `laravel/mcp:^0.8` and `laravel/passport:^13.7`.
- `app/Models/User.php` - implement Passport's `OAuthenticatable` contract and use `HasApiTokens`.
- `config/auth.php` - add the Passport-backed `api` guard.
- `app/Providers/AppServiceProvider.php` - set Passport's MCP authorization view.
- `bootstrap/app.php` - exclude MCP endpoint from CSRF only if the published Laravel MCP route is treated as web middleware in this app.
- `routes/web.php` - add authenticated approval/rejection routes for pending MCP actions.
- `app/Http/Controllers/Api/TimeEntriesController.php` - replace duplicated project/task/asana checks with `TimeEntryGuard`.
- `app/Http/Controllers/Api/TimersController.php` - replace duplicated project/task/asana checks with `TimeEntryGuard`.
- `.env.example` - document Passport key and OAuth URL expectations if Passport install adds required environment variables.

---

### Task 1: Install Laravel MCP, Add Passport OAuth, And Register The Server

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `app/Models/User.php`
- Modify: `config/auth.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `routes/ai.php`
- Create: `app/Mcp/Servers/InternalToolsServer.php`
- Create: `resources/views/mcp/authorize.blade.php`
- Create: `tests/Feature/Mcp/McpServerAuthTest.php`

**Interfaces:**
- Produces: `App\Mcp\Servers\InternalToolsServer`
- Produces: authenticated MCP endpoint `/mcp/internal`
- Produces: OAuth discovery and client-registration routes from `Mcp::oauthRoutes()`
- Consumes: Passport `auth:api` guard

- [ ] **Step 1: Install the packages and publish MCP routes/views**

Run:

```bash
composer require laravel/mcp:^0.8 laravel/passport:^13.7
php artisan vendor:publish --tag=ai-routes
php artisan vendor:publish --tag=mcp-views
php artisan passport:install
```

Expected:
- `laravel/mcp`, `illuminate/json-schema`, `laravel/passport`, and Passport OAuth dependencies are added to `composer.lock`.
- `routes/ai.php` exists.
- Passport OAuth tables, keys, and clients are installed.
- `resources/views/mcp/authorize.blade.php` exists.

- [ ] **Step 2: Make `User` Passport authenticatable**

Edit `app/Models/User.php` imports:

```php
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
```

Update the class signature and traits:

```php
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
```

- [ ] **Step 3: Add the Passport API guard**

Edit `config/auth.php` and add the `api` guard beside the existing `web` guard:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```

- [ ] **Step 4: Configure the OAuth authorization view**

Edit `app/Providers/AppServiceProvider.php` imports:

```php
use Laravel\Passport\Passport;
```

Add this near the start of `boot()`:

```php
Passport::authorizationView(function ($parameters) {
    return view('mcp.authorize', $parameters);
});
```

- [ ] **Step 5: Keep the authorization view simple and trustworthy**

Create or update `resources/views/mcp/authorize.blade.php` with a focused consent screen that:
- Shows the MCP client name.
- Shows the redirect URI.
- Shows the requested `mcp:use` scope.
- Uses the form/action values provided by Passport's `$parameters`.
- Reuses `resources/views/layouts/app.blade.php` if it can do so without requiring app navigation state.

- [ ] **Step 6: Create the server class**

Create `app/Mcp/Servers/InternalToolsServer.php`:

```php
<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Filter Internal Tools')]
#[Version('1.0.0')]
#[Instructions('Use these tools to manage Filter internal time tracking, clients, projects, tasks, team assignments, reports, and budgets. Destructive actions require the authenticated user to have the same permission in the Internal Tools app.')]
class InternalToolsServer extends Server
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
    ];
}
```

- [ ] **Step 7: Register OAuth routes and the MCP route**

Edit `routes/ai.php`:

```php
<?php

use App\Mcp\Servers\InternalToolsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/internal', InternalToolsServer::class)
    ->middleware([
        'auth:api',
        'throttle:api',
    ]);
```

- [ ] **Step 8: Write OAuth auth tests**

Create `tests/Feature/Mcp/McpServerAuthTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('mcp endpoint requires oauth authentication', function () {
    $this->postJson('/mcp/internal', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertUnauthorized();
});

test('mcp oauth protected resource metadata is published', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp/internal')
        ->assertOk()
        ->assertJsonStructure([
            'resource',
            'authorization_servers',
            'scopes_supported',
        ]);
});

test('mcp endpoint accepts a passport oauth token', function () {
    $user = User::factory()->create(['is_active' => true]);
    Passport::actingAs($user, ['mcp:use']);

    $this->postJson('/mcp/internal', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
            ],
        ])->assertOk();
});
```

- [ ] **Step 9: Run the focused tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Mcp/McpServerAuthTest.php --ci
php artisan route:list --path=oauth
php artisan route:list --path=.well-known
```

Expected:
- All tests pass.
- Route list shows OAuth authorization/token/client routes.
- Route list shows MCP OAuth well-known routes.

---

### Task 2: Add Shared Resolver, Authorization, Mutation Guard, And Serializers

**Files:**
- Create: `app/Domain/Mcp/EntityResolver.php`
- Create: `app/Domain/Mcp/McpAuthorization.php`
- Create: `app/Domain/Mcp/McpMutationGuard.php`
- Create: `app/Domain/Mcp/SerializesMcpModels.php`
- Create: `app/Http/Controllers/McpPendingActionController.php`
- Create: `app/Models/McpAuditLog.php`
- Create: `app/Models/McpPendingAction.php`
- Create: `database/migrations/2026_06_25_120000_create_mcp_audit_logs_table.php`
- Create: `database/migrations/2026_06_25_120100_create_mcp_pending_actions_table.php`
- Create: `resources/views/mcp/pending-action.blade.php`
- Modify: `routes/web.php`
- Create: `app/Mcp/Support/McpToolResponse.php`
- Create: `tests/Feature/Mcp/McpEntityResolverTest.php`
- Create: `tests/Feature/Mcp/McpMutationGuardTest.php`
- Create: `tests/Feature/Mcp/McpPendingActionApprovalTest.php`

**Interfaces:**
- Produces: `EntityResolver::projectForUser(User $actor, ?int $id, ?string $query, ?string $clientQuery = null): Project`
- Produces: `EntityResolver::taskForProject(Project $project, ?int $id, ?string $query): Task`
- Produces: `EntityResolver::client(?int $id, ?string $query): Client`
- Produces: `EntityResolver::targetUser(User $actor, ?int $id, ?string $query): User`
- Produces: `McpAuthorization::assertCanActFor(User $actor, User $target): void`
- Produces: `McpMutationGuard::preview(User $actor, string $toolName, string $action, array $payload, array $targets, array $impact): array`
- Produces: `McpMutationGuard::assertApproved(User $actor, string $toolName, string $action, array $payload, array $targets, ?string $approvalId): McpPendingAction`
- Produces: `McpMutationGuard::audit(User $actor, string $toolName, string $action, string $targetType, string|int|null $targetId, array $payload, bool $approvalRequired): void`
- Produces: `McpMutationGuard::assertExplicitIds(array $data, array $idFields): void`
- Produces: serializers returning plain arrays for MCP JSON responses

- [ ] **Step 1: Write resolver tests**

Cover these cases in `tests/Feature/Mcp/McpEntityResolverTest.php`:
- Exact project code resolves before partial name.
- Users only resolve assigned active projects unless they are admin.
- Ambiguous project query throws `Illuminate\Validation\ValidationException` with candidate IDs and names.
- Project task lookup only returns tasks attached to that project.
- Manager target-user lookup allows direct reports and rejects unrelated users.
- Admin target-user lookup allows any active user.

- [ ] **Step 2: Implement `EntityResolver`**

Core behavior:
- Normalize search terms with `mb_strtolower(trim($value))`.
- Prefer exact `id`, then exact `code`, then exact `name`, then contains match.
- If zero matches, throw `ValidationException::withMessages(['lookup' => 'No matching ...'])`.
- If more than one match, throw `ValidationException::withMessages(['lookup' => 'Ambiguous ...', 'candidates' => json_encode([...])])`.
- Always include `is_archived = false` by default for project/client/task/user resolution unless a search tool explicitly asks for archived rows.

- [ ] **Step 3: Implement `McpAuthorization`**

Rules:
- `assertAdmin(User $actor)` uses `$actor->isAdmin()`.
- `assertCanReadReports(User $actor)` uses `$actor->isManager()`.
- `assertCanActFor(User $actor, User $target)` allows same user, admin, or manager where `$target->reports_to_user_id === $actor->id`.
- `assertCanUseProject(User $actor, Project $project)` allows admins or users assigned through `project_user`.
- `assertCanMutateEntry(User $actor, TimeEntry $entry)` delegates to `assertCanActFor($actor, $entry->user)`.

- [ ] **Step 4: Create the MCP audit table and model**

Migration columns:
- `id`
- `actor_user_id` foreign key to `users.id`
- `tool_name` string
- `action` string
- `target_type` nullable string
- `target_id` nullable string
- `payload_hash` string length 64
- `approval_required` boolean
- `approved_at` nullable timestamp
- `ip_address` nullable string length 45
- `user_agent` nullable text
- timestamps

Indexes:
- `actor_user_id, created_at`
- `tool_name, created_at`
- `target_type, target_id`

- [ ] **Step 5: Create the pending approval table, model, routes, and UI**

Migration columns for `mcp_pending_actions`:
- `id` uuid primary key
- `actor_user_id` foreign key to `users.id`
- `tool_name` string
- `action` string
- `summary` string
- `impact` json
- `resolved_ids` json
- `payload_hash` string length 64
- `target_state_hash` string length 64
- `payload` json
- `approved_at` nullable timestamp
- `approved_by_user_id` nullable foreign key to `users.id`
- `rejected_at` nullable timestamp
- `expires_at` timestamp
- timestamps

Routes in `routes/web.php` under `auth` middleware:
- `GET /mcp/pending-actions/{pendingAction}` -> show approval page.
- `POST /mcp/pending-actions/{pendingAction}/approve` -> approve only if `auth()->id() === actor_user_id`.
- `POST /mcp/pending-actions/{pendingAction}/reject` -> reject only if `auth()->id() === actor_user_id`.

Approval view requirements:
- Show client-safe action summary, resolved IDs, impact list, expiry time, and tool name.
- Provide Approve and Reject buttons.
- Never auto-submit or approve from query parameters.

- [ ] **Step 6: Write mutation guard tests**

Cover these cases in `tests/Feature/Mcp/McpMutationGuardTest.php`:
- Preview creates one `mcp_pending_actions` row and returns `requires_approval: true`, `approval_id`, `approval_url`, `expires_at`, `summary`, `resolved_ids`, and `impact`.
- Execution succeeds only after the pending action is approved by the same actor.
- Execution fails for a pending action owned by a different actor.
- Execution fails when payload changes after preview.
- Execution fails when a target's `updated_at` changes after preview.
- Execution fails after the 10-minute expiry.
- Execution fails when the pending action is rejected.
- `assertExplicitIds()` rejects high-impact execution payloads that only include names.
- `audit()` writes one `mcp_audit_logs` row with a stable SHA-256 payload hash.

- [ ] **Step 7: Write pending action web approval tests**

Cover these cases in `tests/Feature/Mcp/McpPendingActionApprovalTest.php`:
- Unauthenticated users are redirected to login.
- The pending action owner can view the approval page.
- A different user cannot view, approve, or reject the pending action.
- Approving records `approved_at` and `approved_by_user_id`.
- Rejecting records `rejected_at`.
- Expired pending actions cannot be approved.

- [ ] **Step 8: Implement `McpMutationGuard`**

Core behavior:
- Normalize payloads by recursively sorting keys before hashing.
- Compute target state hash from target ids and each target's `updated_at` value where available.
- `preview()` stores the normalized payload, target state hash, impact, resolved IDs, and expiry in `mcp_pending_actions`.
- Return `approval_url` using a signed or opaque route to `mcp.pending-actions.show`.
- `assertApproved()` loads the pending action and rejects missing, expired, rejected, unapproved, wrong actor, wrong tool/action, changed payload hash, or changed target state hash.
- Return a validation error named `approval_id` for missing, expired, mismatched, unapproved, rejected, or stale pending actions.
- `assertExplicitIds()` must require fields such as `entry_id`, `client_id`, `project_id`, `task_id`, and `user_id` on approved high-impact writes.

- [ ] **Step 9: Implement serializers**

Serializers must include stable IDs and human-readable labels:
- `timeEntry(TimeEntry $entry): array`
- `project(Project $project): array`
- `client(Client $client): array`
- `task(Task $task): array`
- `user(User $user): array`
- `assignment(Project $project, User $user): array`
- `budget(Project $project, ?BudgetStatus $status): array`

- [ ] **Step 10: Run resolver and guard tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Mcp/McpEntityResolverTest.php tests/Feature/Mcp/McpMutationGuardTest.php tests/Feature/Mcp/McpPendingActionApprovalTest.php --ci
```

Expected: all tests pass.

---

### Task 3: Extract Time Entry Guard From Existing API Controllers

**Files:**
- Create: `app/Domain/TimeTracking/TimeEntryGuard.php`
- Modify: `app/Http/Controllers/Api/TimeEntriesController.php`
- Modify: `app/Http/Controllers/Api/TimersController.php`
- Modify: `tests/Feature/Api/PersonalAccessTokenTest.php`

**Interfaces:**
- Produces: `TimeEntryGuard::assertProjectTaskAllowed(User $user, int $projectId, int $taskId, ?string $asanaTaskGid): Project`
- Consumes: existing `Project`, `AsanaTask`, and project task/user relationships

- [ ] **Step 1: Add guard tests to existing API tests**

Keep the existing assertions and add:
- Starting a timer rejects a task not attached to the project with `task_not_on_project`.
- Creating a time entry rejects an unassigned project with `project_not_assigned`.
- Creating a time entry on an Asana-required linked project rejects missing `asana_task_gid`.

- [ ] **Step 2: Implement `TimeEntryGuard`**

Behavior must match current controller responses:
- User not assigned: throw `AuthorizationException('project_not_assigned')`.
- Task not on project: throw `ValidationException::withMessages(['task_id' => 'task_not_on_project'])`.
- Missing required Asana task: throw `ValidationException::withMessages(['asana_task_gid' => 'asana_task_required'])`.
- Invalid Asana task: throw `ValidationException::withMessages(['asana_task_gid' => 'asana_task_invalid'])`.

- [ ] **Step 3: Replace duplicated controller logic**

Update `TimeEntriesController::store()` and `TimersController::start()` to call the guard and translate exceptions into the same JSON error codes and HTTP statuses currently returned.

- [ ] **Step 4: Run regression tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Api/PersonalAccessTokenTest.php tests/Feature/Timesheet/TimeEntryServiceTest.php tests/Feature/Timesheet/TimerTest.php --ci
```

Expected: all tests pass.

---

### Task 4: Implement Time Tracking MCP Tools

**Files:**
- Create: `app/Mcp/Tools/GetCapabilitiesTool.php`
- Create: `app/Mcp/Tools/SearchProjectsTool.php`
- Create: `app/Mcp/Tools/SearchTasksTool.php`
- Create: `app/Mcp/Tools/StartTimerTool.php`
- Create: `app/Mcp/Tools/StopTimerTool.php`
- Create: `app/Mcp/Tools/GetRunningTimerTool.php`
- Create: `app/Mcp/Tools/LogTimeEntryTool.php`
- Create: `app/Mcp/Tools/UpdateTimeEntryTool.php`
- Create: `app/Mcp/Tools/DeleteTimeEntryTool.php`
- Create: `app/Mcp/Tools/ListTimeEntriesTool.php`
- Modify: `app/Mcp/Servers/InternalToolsServer.php`
- Create: `tests/Feature/Mcp/McpTimeTrackingToolsTest.php`

**Interfaces:**
- Consumes: `EntityResolver`, `McpAuthorization`, `McpMutationGuard`, `TimeEntryGuard`, `TimeEntryService`, `HoursParser`, `SerializesMcpModels`
- Produces: timer and time-entry MCP tools in the catalog

- [ ] **Step 1: Write MCP tool tests**

Use `InternalToolsServer::tool(ToolClass::class, [...])` where possible. Cover:
- `get-capabilities` returns role and allowed actions for user, manager, admin.
- `start-timer` accepts project code/name plus task name and returns a running entry.
- `start-timer` stops the previous running timer for the same user.
- `stop-timer` returns an MCP error when no timer is running.
- `log-time-entry` parses `1:30` and `1.5`.
- Manager can log for direct report and cannot log for unrelated user.
- Manager logging for a direct report writes immediately after authorization and audit logging.
- Admin updating another user's entry requires pending approval in the web UI.
- `delete-time-entry` always requires pending approval in the web UI.
- User cannot update another user's entry.
- `delete-time-entry` rejects execution without `confirm: true` and an approved `approval_id`.
- `update-time-entry` for another user's entry rejects execution without `confirm: true` and an approved `approval_id`.
- Every successful time-tracking write creates an `mcp_audit_logs` row.
- `list-time-entries` filters by date range and project.

- [ ] **Step 2: Implement tool schemas**

Use `Illuminate\Contracts\JsonSchema\JsonSchema` in each tool. Required fields:
- Dates: `Y-m-d`.
- Hours: string, parsed by existing `HoursParser`.
- IDs: integer.
- Names/search terms: string max 255.
- `group_by`: enum where relevant.
- High-impact variants also accept `confirm` boolean and `approval_id` string. These fields are optional for preview and required for execution after the user approves the pending action in the web UI.

- [ ] **Step 3: Implement tool handlers**

Pattern for every handler:
1. `$data = $request->validate([...])`.
2. `$actor = $request->user()`.
3. Resolve target user/project/task/client.
4. Authorize.
5. For standard writes, call the domain service and `McpMutationGuard::audit()`.
6. For high-impact writes without an approved pending action, return `McpMutationGuard::preview(...)` and do not mutate data.
7. For approved high-impact writes, call `McpMutationGuard::assertExplicitIds(...)`, then `McpMutationGuard::assertApproved(...)`, then mutate and audit.
8. Return `Response::json([...])`.

- [ ] **Step 4: Register tools**

Update `InternalToolsServer::$tools` with all tools from this task in the same order as the catalog.

- [ ] **Step 5: Run focused tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Mcp/McpTimeTrackingToolsTest.php --ci
```

Expected: all tests pass.

---

### Task 5: Implement Reporting And Budget MCP Tools

**Files:**
- Create: `app/Domain/Reporting/McpTimeReportService.php`
- Create: `app/Mcp/Tools/TimeReportTool.php`
- Create: `app/Mcp/Tools/ProjectBudgetStatusTool.php`
- Modify: `app/Mcp/Servers/InternalToolsServer.php`
- Create: `tests/Feature/Mcp/McpReportingToolsTest.php`

**Interfaces:**
- Consumes: `TimeReportQuery`, `ProjectBudgetCalculator`, `GroupBy`, `EntityResolver`, `McpAuthorization`
- Produces: grouped report arrays with `total_hours`, `billable_hours`, `non_billable_hours`, `billable_amount`

- [ ] **Step 1: Write report tests**

Cover:
- Admin can request May grouped by client.
- Manager can request direct report scoped report.
- Regular user can request own report only.
- `billable_only` excludes non-billable entries.
- Budget status returns monthly CI as `scope: monthly_cumulative`.
- Budget status returns fixed fee as `scope: lifetime`.

- [ ] **Step 2: Implement `McpTimeReportService`**

Methods:
- `timeReport(CarbonImmutable $from, CarbonImmutable $to, GroupBy $groupBy, ?int $userId, ?int $clientId, ?int $projectId, bool $billableOnly): array`
- `projectBudget(Project $project, ?CarbonImmutable $asOf = null): array`

Use `TimeReportQuery::totals()` and `TimeReportQuery::groupBy()`. Compute non-billable as `round($row->total_hours - $row->billable_hours, 2)`.

- [ ] **Step 3: Implement MCP tools**

`TimeReportTool` validates:
- `from` required date.
- `to` required date and not before `from`.
- `group_by` required enum: `client`, `project`, `task`, `user`.

`ProjectBudgetStatusTool` validates:
- `project_id` or `project`.
- `as_of` optional date.

- [ ] **Step 4: Run focused tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Mcp/McpReportingToolsTest.php tests/Feature/Reporting/TimeReportQueryTest.php tests/Feature/Budgeting/ProjectBudgetCalculatorTest.php --ci
```

Expected: all tests pass.

---

### Task 6: Extract Admin Command Services

**Files:**
- Create: `app/Domain/Clients/ClientCommandService.php`
- Create: `app/Domain/Projects/ProjectCommandService.php`
- Create: `app/Domain/Tasks/TaskCommandService.php`
- Modify: `app/Livewire/Admin/Clients/Index.php`
- Modify: `app/Livewire/Admin/Projects/Create.php`
- Modify: `app/Livewire/Admin/Projects/Edit.php`
- Modify: `app/Livewire/Admin/Tasks/Index.php`
- Modify: existing admin tests only if method calls need updated setup

**Interfaces:**
- Produces: service methods reusable by Livewire and MCP
- Consumes: existing validation rules and sync behavior from admin Livewire components

- [ ] **Step 1: Write or extend admin regression tests**

Cover:
- Client create/update/archive still works.
- Project create/update/archive still works.
- Project task sync preserves task default billability.
- Project user sync stores `hourly_rate_override`.
- Task create/update/archive still works.

- [ ] **Step 2: Implement command services**

Move business operations out of Livewire components without changing UI state:
- `ClientCommandService::create(array $data): Client`
- `ClientCommandService::update(Client $client, array $data): Client`
- `ClientCommandService::setArchived(Client $client, bool $archived): Client`
- `ProjectCommandService::create(array $data, array $taskIds = [], array $userAssignments = []): Project`
- `ProjectCommandService::update(Project $project, array $data, array $taskIds, array $userAssignments): Project`
- `ProjectCommandService::setArchived(Project $project, bool $archived): Project`
- `TaskCommandService::create(array $data): Task`
- `TaskCommandService::update(Task $task, array $data): Task`
- `TaskCommandService::setArchived(Task $task, bool $archived): Task`

- [ ] **Step 3: Refactor Livewire components**

Keep public properties and UI behavior unchanged. Replace direct model mutation in `create()`, `save()`, and archive methods with calls to the new services.

- [ ] **Step 4: Run admin regression tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Admin/Phase3AdminTest.php tests/Feature/Admin/ProjectTeamManagementTest.php tests/Feature/Admin/UsersTest.php tests/Feature/Admin/Phase4RatesTest.php --ci
```

Expected: all tests pass.

---

### Task 7: Implement Admin MCP Tools

**Files:**
- Create: `app/Mcp/Tools/SearchClientsTool.php`
- Create: `app/Mcp/Tools/SearchTeamMembersTool.php`
- Create: `app/Mcp/Tools/CreateClientTool.php`
- Create: `app/Mcp/Tools/UpdateClientTool.php`
- Create: `app/Mcp/Tools/CreateProjectTool.php`
- Create: `app/Mcp/Tools/UpdateProjectTool.php`
- Create: `app/Mcp/Tools/ArchiveProjectTool.php`
- Create: `app/Mcp/Tools/CreateTaskTool.php`
- Create: `app/Mcp/Tools/UpdateTaskTool.php`
- Create: `app/Mcp/Tools/ListProjectAssignmentsTool.php`
- Create: `app/Mcp/Tools/AssignProjectMemberTool.php`
- Create: `app/Mcp/Tools/UnassignProjectMemberTool.php`
- Modify: `app/Mcp/Servers/InternalToolsServer.php`
- Create: `tests/Feature/Mcp/McpAdminToolsTest.php`

**Interfaces:**
- Consumes: admin command services, resolver, authorization, `McpMutationGuard`, serializers
- Produces: client/project/task/team assignment MCP tools in the catalog

- [ ] **Step 1: Write admin MCP tests**

Cover:
- Regular user cannot create clients/projects/tasks.
- Manager can search clients and team members but cannot mutate them.
- Admin can create/update/archive a client after authorization and audit logging.
- Admin can create/update/archive a project after authorization and audit logging.
- Admin can create/update/archive a task after authorization and audit logging.
- Admin can list assignments with hourly overrides.
- Admin can assign and unassign a teammate after authorization and audit logging.
- Admin mutations that touch existing records should prefer explicit IDs, but they do not require pending web approval unless a future true delete-client or delete-project tool is added.
- Every successful admin MCP mutation creates an `mcp_audit_logs` row.

- [ ] **Step 2: Implement search/read tools**

Search tools should return at most 25 rows by default and accept `limit` max 100. Each row must include `id`, `name`, `is_archived`, and any relevant code/client/project metadata.

- [ ] **Step 3: Implement mutation tools**

Mutation tools must:
- Call `McpAuthorization::assertAdmin()`.
- Validate input with the same limits as Livewire components.
- Use command services after authorization passes.
- Call `McpMutationGuard::audit(...)` after a successful mutation.
- Return the serialized updated model plus `audit_id`, changed target ids, and a concise before/after summary.

- [ ] **Step 4: Register tools**

Append admin tools to `InternalToolsServer::$tools`.

- [ ] **Step 5: Run focused tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Mcp/McpAdminToolsTest.php --ci
```

Expected: all tests pass.

---

### Task 8: OAuth Documentation And End-To-End Verification

**Files:**
- Create: `docs/MCP.md`
- Modify: `README.md` only if the project README already has a local setup section for integrations

**Interfaces:**
- Produces: user-facing OAuth setup docs for ChatGPT, Claude, Claude Code, Cursor, and MCP Inspector

- [ ] **Step 1: Add docs**

Create `docs/MCP.md` with:
- Endpoint: `/mcp/internal`.
- Auth: OAuth 2.1 via Passport. Users authorize the MCP client in a browser and do not copy a profile API token into the client.
- Local URL example: `http://127.0.0.1:8000/mcp/internal`.
- Production URL: use the deployed Internal Tools base URL plus `/mcp/internal`.
- OAuth metadata endpoints:
  - `/.well-known/oauth-protected-resource/mcp/internal`
  - `/.well-known/oauth-authorization-server`
- OAuth scope: `mcp:use`.
- Example Claude Code command:

```bash
claude mcp add --transport http filter-internal --scope user https://internal.example.com/mcp/internal
```

- Example Cursor config:

```json
{
  "mcpServers": {
    "filter-internal": {
      "url": "https://internal.example.com/mcp/internal"
    }
  }
}
```

- ChatGPT setup notes:
  - Enable developer mode if required by the current ChatGPT connector UI.
  - Create a custom app or connector with `https://internal.example.com/mcp/internal`.
  - Complete the browser authorization prompt against the Internal Tools app.
- Example smoke prompts from the ticket.
- Permission summary.
- Mutation guardrail summary:
  - Read-only tools execute immediately.
  - Standard writes execute after authorization and create audit rows.
  - High-impact writes are limited to updating another user's time entry, deleting a time entry, and any future true project/client deletion tools.
  - High-impact writes return a pending approval preview first.
  - Approved high-impact writes require `confirm: true`, `approval_id`, and explicit resolved IDs.
  - Pending approvals expire after 10 minutes and are invalidated when the target record changes.
- Troubleshooting for OAuth redirect mismatch, cancelled authorization, 401, 403, ambiguous lookup, and missing project assignment.

- [ ] **Step 2: Add deployment notes**

Add deployment notes to `docs/MCP.md`:
- Run `php artisan passport:install` in each environment.
- Keep Passport private keys out of source control.
- Ensure `APP_URL` is the public HTTPS base URL so OAuth metadata and redirects use the correct origin.
- Use a root-domain or subdomain deployment for the MCP endpoint where possible, because some MCP clients resolve well-known OAuth metadata at the host root.
- After deployment, verify `/.well-known/oauth-protected-resource/mcp/internal` and `/.well-known/oauth-authorization-server` return JSON over HTTPS.

- [ ] **Step 3: Run full verification**

Run:

```bash
./vendor/bin/pest --ci
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
npm run build
php artisan route:list --path=mcp
php artisan route:list --path=oauth
php artisan route:list --path=.well-known
```

Expected:
- Pest passes.
- PHPStan passes.
- Pint reports no style changes needed.
- Vite build succeeds.
- Route list shows `/mcp/internal`.
- Route list shows Passport OAuth routes.
- Route list shows MCP OAuth well-known routes.

- [ ] **Step 4: Manual OAuth MCP smoke test**

Run:

```bash
php artisan serve
php artisan mcp:inspector filter-internal
```

Then connect to `http://127.0.0.1:8000/mcp/internal`, complete the browser OAuth authorization flow, and verify:
- `get-capabilities`
- `search-projects` with `Internal`
- `start-timer` for `Internal` / `Admin`
- `get-running-timer`
- `stop-timer`
- `list-time-entries` for today's date

Expected: each tool returns structured JSON and the timer round-trip creates one stopped time entry.

---

## Rollout Notes

- Release the tool catalog as a single MCP server rather than many endpoints; MCP clients discover tools from the server metadata.
- Keep the old `/api/*` token endpoints. They are already used by the Freshdesk widget and are useful for non-MCP automation.
- Log MCP failures through Laravel logs and audit successful MCP writes in `mcp_audit_logs`. Do not add a separate failed-attempt audit table in the first release unless usage/debugging proves it is needed.
- OAuth is the supported MCP setup path. Do not document profile API tokens as the MCP connection method unless a separate legacy/manual MCP endpoint is intentionally added later.
- High-impact MCP writes must stay behind `McpMutationGuard`; do not expand or bypass the preview/approval flow without explicitly revisiting the safety classification.

## Self-Review

- Spec coverage: all ticket capabilities are represented in the tool catalog and task breakdown.
- Existing-code fit: the plan reuses `TimeEntryService`, `HoursParser`, `TimeReportQuery`, `ProjectBudgetCalculator`, current role gates, and existing `/api/*` token endpoints while adding OAuth only to the MCP surface.
- Main risk: the number of tool classes is large and the mutation surface is powerful. Mitigation is to build them in slices and make all high-impact writes consume the shared `McpMutationGuard` with focused tests before any admin tools ship.
- Deferred intentionally: invoice and expense tools from the public Harvest MCP reference are not in the Asana ticket and do not map to current Internal Tools models.
