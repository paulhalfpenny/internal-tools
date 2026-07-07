<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Enums\Role;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Projects\Create as AdminProjectCreate;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Tasks\Index as AdminTasks;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admins can set agency and jdw billable defaults on tasks', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(AdminTasks::class)
        ->set('name', 'Holiday')
        ->set('isDefaultBillable', false)
        ->set('isJdwDefaultBillable', true)
        ->set('colour', '#123456')
        ->call('create')
        ->assertHasNoErrors();

    $task = Task::where('name', 'Holiday')->firstOrFail();

    expect($task->is_default_billable)->toBeFalse()
        ->and($task->is_jdw_default_billable)->toBeTrue();

    Livewire::test(AdminTasks::class)
        ->call('edit', $task->id)
        ->set('editIsDefaultBillable', true)
        ->set('editIsJdwDefaultBillable', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($task->fresh()->is_default_billable)->toBeTrue()
        ->and($task->fresh()->is_jdw_default_billable)->toBeFalse();
});

test('project default task billability uses agency or jdw task defaults', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create([
        'name' => 'JDW',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);
    $task = Task::factory()->create([
        'name' => 'Holiday',
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);

    $agencyClient->defaultTasks()->attach($task->id, ['sort_order' => 0]);
    $jdwClient->defaultTasks()->attach($task->id, ['sort_order' => 0]);

    Livewire::test(AdminProjects::class)
        ->set('clientId', $agencyClient->id)
        ->set('code', 'AGY-001')
        ->set('name', 'Agency project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AdminProjects::class)
        ->set('clientId', $jdwClient->id)
        ->set('code', 'JDW-001')
        ->set('name', 'JDW project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    $agencyProject = Project::where('code', 'AGY-001')->firstOrFail();
    $jdwProject = Project::where('code', 'JDW-001')->firstOrFail();

    expect((bool) $agencyProject->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeFalse()
        ->and((bool) $jdwProject->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});

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

test('legacy project create page applies agency or jdw task defaults to project tasks', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create([
        'name' => 'JDW Projects',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);
    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);

    $agencyClient->defaultTasks()->attach($task->id, ['sort_order' => 0]);
    $jdwClient->defaultTasks()->attach($task->id, ['sort_order' => 0]);

    Livewire::test(AdminProjectCreate::class)
        ->set('clientId', $agencyClient->id)
        ->set('code', 'LEG-AGY')
        ->set('name', 'Legacy agency project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AdminProjectCreate::class)
        ->set('clientId', $jdwClient->id)
        ->set('code', 'LEG-JDW')
        ->set('name', 'Legacy JDW project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    $agencyProject = Project::where('code', 'LEG-AGY')->firstOrFail();
    $jdwProject = Project::where('code', 'LEG-JDW')->firstOrFail();

    expect((bool) $agencyProject->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeFalse()
        ->and((bool) $jdwProject->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});

test('project edit page labels tasks not billable using the project agency or jdw default', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create([
        'name' => 'JDW Projects',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);

    // Billable for JDW, not for Agency.
    Task::factory()->create([
        'name' => 'Programme Activity',
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);

    $agencyProject = Project::factory()->create(['client_id' => $agencyClient->id]);
    $jdwProject = Project::factory()->create(['client_id' => $jdwClient->id]);

    // On an Agency project the task is non-billable, so it is flagged.
    Livewire::test(AdminProjectEdit::class, ['project' => $agencyProject])
        ->assertSee('(not billable)');

    // On a JDW project the same task is billable, so it must NOT be flagged.
    Livewire::test(AdminProjectEdit::class, ['project' => $jdwProject])
        ->assertDontSee('(not billable)');
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

test('saving project assignments reapplies agency or jdw task defaults', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create([
        'name' => 'JDW',
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ]);
    $task = Task::factory()->create([
        'is_default_billable' => false,
        'is_jdw_default_billable' => true,
    ]);

    $agencyProject = Project::factory()->create(['client_id' => $agencyClient->id]);
    $jdwProject = Project::factory()->create(['client_id' => $jdwClient->id]);

    Livewire::test(AdminProjectEdit::class, ['project' => $agencyProject])
        ->set('taskAssignments', [$task->id])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(AdminProjectEdit::class, ['project' => $jdwProject])
        ->set('taskAssignments', [$task->id])
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) $agencyProject->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeFalse()
        ->and((bool) $jdwProject->fresh()->tasks()->whereKey($task->id)->firstOrFail()->pivot->is_billable)->toBeTrue();
});
