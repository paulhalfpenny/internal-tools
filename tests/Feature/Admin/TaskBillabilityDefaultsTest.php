<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Enums\Role;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Tasks\Index as AdminTasks;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('project task billability uses explicit client profile instead of client name', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyProfileClient = Client::factory()->create([
        'name' => 'JDW Named Agency Client',
        'task_billability_profile' => ClientTaskBillabilityProfile::Agency,
    ]);
    $jdwProfileClient = Client::factory()->create([
        'name' => 'Plain Client',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);
    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);

    $agencyProfileClient->defaultTasks()->attach($task->id, ['sort_order' => 0]);
    $jdwProfileClient->defaultTasks()->attach($task->id, ['sort_order' => 0]);

    Livewire::test(AdminProjects::class)
        ->set('clientId', $agencyProfileClient->id)
        ->set('code', 'PROFILE-AGENCY')
        ->set('name', 'Agency profile project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AdminProjects::class)
        ->set('clientId', $jdwProfileClient->id)
        ->set('code', 'PROFILE-JDW')
        ->set('name', 'JDW profile project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    $agencyProfileProject = Project::where('code', 'PROFILE-AGENCY')->firstOrFail();
    $jdwProfileProject = Project::where('code', 'PROFILE-JDW')->firstOrFail();

    expect((bool) $agencyProfileProject->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeFalse()
        ->and((bool) $jdwProfileProject->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});

test('editing a task billable default re-syncs existing project task pivots', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create([
        'name' => 'JDW Projects',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);

    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => false,
    ]);

    $agencyProject = Project::factory()->create(['client_id' => $agencyClient->id]);
    $jdwProject = Project::factory()->create(['client_id' => $jdwClient->id]);

    // Existing links reflect the old (non-billable) defaults.
    $agencyProject->tasks()->attach($task->id, ['is_billable' => false]);
    $jdwProject->tasks()->attach($task->id, ['is_billable' => false]);

    // Admin turns on JDW billable for the task in Admin > Tasks.
    Livewire::test(AdminTasks::class)
        ->call('edit', $task->id)
        ->set('editIsDefaultBillable', false)
        ->set('editIsJdwDefaultBillable', true)
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) $agencyProject->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeFalse()
        ->and((bool) $jdwProject->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});

test('editing a client task profile re-syncs existing project task pivots', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $client = Client::factory()->create([
        'name' => 'Plain Client',
        'task_billability_profile' => ClientTaskBillabilityProfile::Agency,
    ]);
    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $project->tasks()->attach($task->id, ['is_billable' => false]);

    Livewire::test(AdminClients::class)
        ->call('edit', $client->id)
        ->set('editTaskBillabilityProfile', ClientTaskBillabilityProfile::Jdw->value)
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) $project->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});
