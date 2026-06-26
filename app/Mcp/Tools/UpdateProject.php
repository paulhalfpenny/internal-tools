<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\Mcp\McpAuditService;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a project. This is an admin-only standard write and does not require web approval.')]
class UpdateProject extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Internal Tools project ID to update.')
                ->required(),
            'client_id' => $schema->integer()
                ->description('Optional replacement Internal Tools client ID.')
                ->nullable(),
            'manager_user_id' => $schema->integer()
                ->description('Optional replacement Internal Tools user ID for the project manager. Send null to clear it.')
                ->nullable(),
            'code' => $schema->string()
                ->description('Optional replacement unique project code, up to 50 characters. Send null to clear it.')
                ->max(50)
                ->nullable(),
            'name' => $schema->string()
                ->description('Optional replacement project name.')
                ->max(255),
            'is_billable' => $schema->boolean()
                ->description('Optional replacement billable flag.'),
            'default_hourly_rate' => $schema->number()
                ->description('Optional replacement default hourly rate. Send null to clear it.')
                ->min(0)
                ->nullable(),
            'budget_type' => $schema->string()
                ->description('Optional replacement budget type. Send null to clear it.')
                ->enum(['fixed_fee', 'monthly_ci'])
                ->nullable(),
            'budget_amount' => $schema->number()
                ->description('Optional replacement budget amount. Send null to clear it.')
                ->min(0)
                ->nullable(),
            'budget_hours' => $schema->number()
                ->description('Optional replacement budget hours. Send null to clear it.')
                ->min(0)
                ->nullable(),
            'budget_starts_on' => $schema->string()
                ->format('date')
                ->description('Optional replacement budget start date in YYYY-MM-DD format. Send null to clear it.')
                ->nullable(),
            'starts_on' => $schema->string()
                ->format('date')
                ->description('Optional replacement project start date in YYYY-MM-DD format. Send null to clear it.')
                ->nullable(),
            'ends_on' => $schema->string()
                ->format('date')
                ->description('Optional replacement project end date in YYYY-MM-DD format. Send null to clear it.')
                ->nullable(),
            'asana_task_required' => $schema->boolean()
                ->description('Optional replacement flag for whether time logged to this project must include an Asana task.'),
            'task_ids' => $schema->array()
                ->description('Optional replacement list of Internal Tools task IDs assigned to the project.')
                ->items($schema->integer()),
            'user_ids' => $schema->array()
                ->description('Optional replacement list of Internal Tools user IDs assigned to the project.')
                ->items($schema->integer()),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions, McpAuditService $audit)
    {
        $user = $this->user($request);
        $input = $request->all();
        $request->validate(['project_id' => ['required', 'integer', 'exists:projects,id']]);

        $project = Project::findOrFail((int) $input['project_id']);
        $project = $actions->updateProject($user, $project, Arr::except($input, ['project_id']));
        $result = ['project_id' => $project->id];

        $this->auditCompleted($audit, $user, 'update_project', $input, ['approval_required' => false, ...$result], $project);

        return $this->ok($result);
    }
}
