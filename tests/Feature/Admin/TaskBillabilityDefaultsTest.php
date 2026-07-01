<?php

use App\Enums\Role;
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
    $jdwClient = Client::factory()->create(['name' => 'JDW']);
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

test('saving project assignments reapplies agency or jdw task defaults', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $agencyClient = Client::factory()->create(['name' => 'Agency Client']);
    $jdwClient = Client::factory()->create(['name' => 'JDW']);
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
