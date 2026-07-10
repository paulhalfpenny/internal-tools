<?php

use App\Enums\Role;
use App\Livewire\Reports\ClientsReport;
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
