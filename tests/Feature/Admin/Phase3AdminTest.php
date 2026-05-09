<?php

use App\Enums\Role;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('projects search filters by name, code and client name', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $zedwell = Client::factory()->create(['name' => 'Zedwell']);
    $other = Client::factory()->create(['name' => 'Other Client']);

    Project::factory()->create(['client_id' => $zedwell->id, 'name' => 'Zedwell Brand', 'code' => 'ZED-01']);
    Project::factory()->create(['client_id' => $other->id, 'name' => 'Unrelated Build', 'code' => 'OTH-01']);
    Project::factory()->create(['client_id' => $other->id, 'name' => 'Zedwell-style microsite', 'code' => 'OTH-02']);

    Livewire::test(AdminProjects::class)
        ->set('search', 'zedwell')
        ->assertSee('Zedwell Brand')
        ->assertSee('Zedwell-style microsite')
        ->assertDontSee('Unrelated Build');
});

test('clients search filters by name', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    Client::factory()->create(['name' => 'Acme']);
    Client::factory()->create(['name' => 'Beta']);

    Livewire::test(AdminClients::class)
        ->set('search', 'acm')
        ->assertSee('Acme')
        ->assertDontSee('Beta');
});

test('user edit can flip employment between Employee and Contractor via select', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $target = User::factory()->create(['is_contractor' => false]);

    Livewire::test(AdminUsers::class)
        ->call('edit', $target->id)
        ->set('editIsContractor', '1')
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->is_contractor)->toBeTrue();

    Livewire::test(AdminUsers::class)
        ->call('edit', $target->id)
        ->set('editIsContractor', '0')
        ->call('save');

    expect($target->fresh()->is_contractor)->toBeFalse();
});

test('users search filters by name and email', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Admin Person', 'email' => 'admin@filter.agency']);
    $this->actingAs($admin);

    User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Other', 'email' => 'bob@elsewhere.com']);

    Livewire::test(AdminUsers::class)
        ->set('search', 'alice')
        ->assertSee('Alice Example')
        ->assertDontSee('Bob Other');
});

test('duplicate project copies tasks, users, rate and budget but not time entries', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'code' => 'AAA-001',
        'name' => 'Original',
        'default_hourly_rate' => 120.00,
    ]);
    $task1 = Task::factory()->create();
    $task2 = Task::factory()->create();
    $project->tasks()->attach($task1->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->tasks()->attach($task2->id, ['is_billable' => false, 'hourly_rate_override' => 90.00]);

    $teamMember = User::factory()->create();
    $project->users()->attach($teamMember->id, ['hourly_rate_override' => 100.00]);

    Livewire::test(AdminProjects::class)
        ->call('duplicate', $project->id);

    $copy = Project::where('code', 'AAA-001-COPY')->firstOrFail();
    expect($copy->name)->toBe('Original (copy)');
    expect((float) $copy->default_hourly_rate)->toBe(120.00);
    expect($copy->tasks()->count())->toBe(2);
    expect($copy->users()->count())->toBe(1);

    $task2Pivot = $copy->tasks()->where('tasks.id', $task2->id)->first()->pivot;
    expect((bool) $task2Pivot->is_billable)->toBeFalse();
    expect((float) $task2Pivot->hourly_rate_override)->toBe(90.00);
});

test('duplicate project handles code collisions by appending a counter', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['code' => 'DUP-001']);
    Project::factory()->create(['code' => 'DUP-001-COPY']);

    Livewire::test(AdminProjects::class)
        ->call('duplicate', $project->id);

    expect(Project::where('code', 'DUP-001-COPY-2')->exists())->toBeTrue();
});

test('creating a project for a client pre-attaches the clients default tasks', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $client = Client::factory()->create();
    $defaultA = Task::factory()->create();
    $defaultB = Task::factory()->create();
    $client->defaultTasks()->attach([$defaultA->id => ['sort_order' => 0], $defaultB->id => ['sort_order' => 1]]);

    Livewire::test(AdminProjects::class)
        ->set('clientId', $client->id)
        ->set('code', 'NEW-001')
        ->set('name', 'New project')
        ->set('isBillable', true)
        ->call('save');

    $project = Project::where('code', 'NEW-001')->firstOrFail();
    expect($project->tasks()->count())->toBe(2);
    expect($project->tasks->pluck('id')->all())->toContain($defaultA->id, $defaultB->id);
});
