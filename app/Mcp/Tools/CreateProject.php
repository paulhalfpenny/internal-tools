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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive(false)]
#[Description('Create a project. This is an admin-only standard write and does not require web approval.')]
class CreateProject extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()
                ->description('Internal Tools client ID the project belongs to.')
                ->required(),
            'code' => $schema->string()
                ->description('Unique project code, up to 50 characters.')
                ->max(50)
                ->required(),
            'name' => $schema->string()
                ->description('Project name.')
                ->max(255)
                ->required(),
            'manager_user_id' => $schema->integer()
                ->description('Optional Internal Tools user ID for the project manager.')
                ->nullable(),
            'is_billable' => $schema->boolean()
                ->description('Whether the project is billable. Defaults to true.'),
            'default_hourly_rate' => $schema->number()
                ->description('Optional default hourly rate for the project.')
                ->min(0)
                ->nullable(),
            'budget_type' => $schema->string()
                ->description('Optional budget type.')
                ->enum(['fixed_fee', 'monthly_ci'])
                ->nullable(),
            'budget_amount' => $schema->number()
                ->description('Optional project budget amount.')
                ->min(0)
                ->nullable(),
            'budget_hours' => $schema->number()
                ->description('Optional project budget hours.')
                ->min(0)
                ->nullable(),
            'budget_starts_on' => $schema->string()
                ->format('date')
                ->description('Optional budget start date in YYYY-MM-DD format.')
                ->nullable(),
            'starts_on' => $schema->string()
                ->format('date')
                ->description('Optional project start date in YYYY-MM-DD format.')
                ->nullable(),
            'ends_on' => $schema->string()
                ->format('date')
                ->description('Optional project end date in YYYY-MM-DD format.')
                ->nullable(),
            'asana_task_required' => $schema->boolean()
                ->description('Whether time logged to this project must include an Asana task. Defaults to true.'),
            'task_ids' => $schema->array()
                ->description('Optional Internal Tools task IDs to assign to the project.')
                ->items($schema->integer()),
            'user_ids' => $schema->array()
                ->description('Optional Internal Tools user IDs to assign to the project.')
                ->items($schema->integer()),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit): ResponseFactory
    {
        $user = $this->user($request);
        $input = $request->all();
        $project = $actions->createProject($user, $input);
        $result = ['project_id' => $project->id];

        $this->auditCompleted($audit, $user, 'create_project', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
