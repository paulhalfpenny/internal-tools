<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('backfill command re-applies task billable defaults to existing project pivots', function () {
    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create([
        'name' => 'JDW Projects',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);

    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);

    $agencyProject = Project::factory()->create(['client_id' => $agencyClient->id]);
    $jdwProject = Project::factory()->create(['client_id' => $jdwClient->id]);

    // Stale pivots: both wrong relative to the task's current defaults.
    $agencyProject->tasks()->attach($task->id, ['is_billable' => true]);
    $jdwProject->tasks()->attach($task->id, ['is_billable' => false]);

    $this->artisan('tasks:backfill-billability')->assertSuccessful();

    expect((bool) $agencyProject->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeFalse()
        ->and((bool) $jdwProject->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});
