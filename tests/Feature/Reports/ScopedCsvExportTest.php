<?php

use App\Enums\Role;
use App\Livewire\Reports\ClientsReport;
use App\Livewire\Reports\ProjectsReport;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function scopedExportEntry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-15',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100.0,
        'billable_amount' => 100.0,
        'invoiced_at' => null,
    ], $attrs));
}

function captureStreamBody($response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('exportForClient on ClientsReport scopes the CSV to that client only', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $clientA = Client::factory()->create(['name' => 'Acme Co']);
    $clientB = Client::factory()->create(['name' => 'Beta Ltd']);
    $projectA = Project::factory()->create(['client_id' => $clientA->id]);
    $projectB = Project::factory()->create(['client_id' => $clientB->id]);

    scopedExportEntry(['user_id' => $user->id, 'project_id' => $projectA->id, 'task_id' => $task->id, 'notes' => 'A entry']);
    scopedExportEntry(['user_id' => $user->id, 'project_id' => $projectB->id, 'task_id' => $task->id, 'notes' => 'B entry']);

    $this->actingAs($admin);

    $component = Livewire::test(ClientsReport::class)
        ->set('from', '2026-04-01')
        ->set('to', '2026-04-30');

    $response = $component->instance()->exportForClient($clientA->id);
    $body = captureStreamBody($response);

    expect($body)->toContain('A entry')->not->toContain('B entry');
    expect($response->headers->get('Content-Disposition'))->toContain('acme-co');
});

test('exportForProject on ProjectsReport scopes the CSV to that project only', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $project1 = Project::factory()->create(['code' => 'PRJ-1']);
    $project2 = Project::factory()->create(['code' => 'PRJ-2']);

    scopedExportEntry(['user_id' => $user->id, 'project_id' => $project1->id, 'task_id' => $task->id, 'notes' => 'P1 entry']);
    scopedExportEntry(['user_id' => $user->id, 'project_id' => $project2->id, 'task_id' => $task->id, 'notes' => 'P2 entry']);

    $this->actingAs($admin);

    $component = Livewire::test(ProjectsReport::class)
        ->set('from', '2026-04-01')
        ->set('to', '2026-04-30');

    $response = $component->instance()->exportForProject($project1->id);
    $body = captureStreamBody($response);

    expect($body)->toContain('P1 entry')->not->toContain('P2 entry');
    expect($response->headers->get('Content-Disposition'))->toContain('prj-1');
});

test('top-level export still returns all entries unscoped', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    $projectA = Project::factory()->create(['client_id' => $clientA->id]);
    $projectB = Project::factory()->create(['client_id' => $clientB->id]);

    scopedExportEntry(['user_id' => $user->id, 'project_id' => $projectA->id, 'task_id' => $task->id, 'notes' => 'A entry']);
    scopedExportEntry(['user_id' => $user->id, 'project_id' => $projectB->id, 'task_id' => $task->id, 'notes' => 'B entry']);

    $this->actingAs($admin);

    $component = Livewire::test(ClientsReport::class)
        ->set('from', '2026-04-01')
        ->set('to', '2026-04-30');

    $response = $component->instance()->export();
    $body = captureStreamBody($response);

    expect($body)->toContain('A entry')->toContain('B entry');
});

test('client detail page renders for a client', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $client = Client::factory()->create(['name' => 'Demo Client']);
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Demo Project']);

    // Ensure the entry falls inside the default 'this_month' preset
    scopedExportEntry([
        'spent_on' => now()->toDateString(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
    ]);

    $this->actingAs($admin);

    $response = $this->get(route('reports.client-detail', $client));
    $response->assertOk();
    $response->assertSee('Demo Client');
    $response->assertSee('Demo Project');
});
