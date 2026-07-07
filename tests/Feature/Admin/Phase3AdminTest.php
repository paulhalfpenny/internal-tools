<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Enums\Role;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Projects\Create as AdminProjectCreate;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Models\AsanaProject;
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

test('projects index sorts projects by client name then project name', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $alphaClient = Client::factory()->create(['name' => 'Alpha Client']);
    $betaClient = Client::factory()->create(['name' => 'Beta Client']);

    Project::factory()->create(['client_id' => $betaClient->id, 'name' => 'Alpha Build', 'code' => 'BET-ALPHA']);
    Project::factory()->create(['client_id' => $alphaClient->id, 'name' => 'Zulu Build', 'code' => 'ALP-ZULU']);
    Project::factory()->create(['client_id' => $alphaClient->id, 'name' => 'Apple Build', 'code' => 'ALP-APPLE']);

    Livewire::test(AdminProjects::class)
        ->assertSeeInOrder([
            'Apple Build',
            'Zulu Build',
            'Alpha Build',
        ]);
});

test('managers can access the admin projects area but not other admin areas', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $regularUser = User::factory()->create(['role' => Role::User]);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $this->actingAs($manager);

    $this->get(route('admin.projects'))->assertOk();
    $this->get(route('admin.projects.edit', $project))->assertOk();
    $this->get(route('admin.users'))->assertForbidden();

    $this->get(route('timesheet'))
        ->assertSee('Admin')
        ->assertSee('href="'.route('admin.projects').'"', false)
        ->assertDontSee('href="'.route('admin.users').'"', false);

    $this->actingAs($regularUser)
        ->get(route('admin.projects'))
        ->assertForbidden();
});

test('managers can create and edit projects from the admin projects area', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $client = Client::factory()->create();

    $this->actingAs($manager);

    Livewire::test(AdminProjects::class)
        ->set('clientId', $client->id)
        ->set('code', 'MGR-001')
        ->set('name', 'Manager-created project')
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::where('code', 'MGR-001')->firstOrFail();
    expect($project->name)->toBe('Manager-created project');

    Livewire::test(AdminProjectEdit::class, ['project' => $project])
        ->set('name', 'Manager-updated project')
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()->name)->toBe('Manager-updated project');
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

test('client admin can set task billability profile', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(AdminClients::class)
        ->set('name', 'Non-named profile client')
        ->set('code', 'NNP')
        ->set('taskBillabilityProfile', ClientTaskBillabilityProfile::Jdw->value)
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::where('code', 'NNP')->firstOrFail();

    expect($client->task_billability_profile)->toBe(ClientTaskBillabilityProfile::Jdw);

    Livewire::test(AdminClients::class)
        ->call('edit', $client->id)
        ->assertSetStrict('editTaskBillabilityProfile', ClientTaskBillabilityProfile::Jdw->value)
        ->set('editTaskBillabilityProfile', ClientTaskBillabilityProfile::Agency->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($client->fresh()->task_billability_profile)->toBe(ClientTaskBillabilityProfile::Agency);
});

test('client edit exposes default task ids as checkbox option values', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $client = Client::factory()->create();
    $defaultA = Task::factory()->create();
    $defaultB = Task::factory()->create();
    $client->defaultTasks()->attach([
        $defaultA->id => ['sort_order' => 0],
        $defaultB->id => ['sort_order' => 1],
    ]);

    Livewire::test(AdminClients::class)
        ->call('edit', $client->id)
        ->assertSetStrict('editDefaultTaskIds', [(string) $defaultA->id, (string) $defaultB->id]);
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

test('saving a freshly duplicated project does not error', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'code' => 'SAV-001',
        'name' => 'Original',
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $teamMember = User::factory()->create();
    $project->users()->attach($teamMember->id, ['hourly_rate_override' => 100.00]);

    Livewire::test(AdminProjects::class)->call('duplicate', $project->id);

    $copy = Project::where('code', 'SAV-001-COPY')->firstOrFail();

    Livewire::test(AdminProjectEdit::class, ['project' => $copy])
        ->call('save')
        ->assertHasNoErrors();

    $copy->refresh();
    expect($copy->users()->count())->toBe(1);
    expect($copy->tasks()->count())->toBe(1);
});

test('project edit exposes non billable value as select option value', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->nonBillable()->create();

    Livewire::test(AdminProjectEdit::class, ['project' => $project])
        ->assertSetStrict('isBillable', '0');
});

test('project create forms expose billable value as select option value', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(AdminProjects::class)
        ->assertSetStrict('isBillable', '1');

    Livewire::test(AdminProjectCreate::class)
        ->assertSetStrict('isBillable', '1');
});

test('saving a project with an Asana gid that another project already uses links both projects', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    AsanaProject::create([
        'gid' => 'shared-gid',
        'workspace_gid' => 'ws-1',
        'name' => 'Shared',
        'is_archived' => false,
    ]);

    $client = Client::factory()->create();
    $taken = Project::factory()->create(['client_id' => $client->id]);
    $taken->asanaProjects()->attach('shared-gid', ['asana_custom_field_gid' => null]);
    $target = Project::factory()->create(['client_id' => $client->id]);

    Livewire::test(AdminProjectEdit::class, ['project' => $target])
        ->set('asanaProjectGids', ['shared-gid'])
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->asanaProjects()->pluck('gid')->all())->toBe(['shared-gid']);
    expect($taken->fresh()->asanaProjects()->pluck('gid')->all())->toBe(['shared-gid']);
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
        ->set('isBillable', '1')
        ->call('save');

    $project = Project::where('code', 'NEW-001')->firstOrFail();
    expect($project->tasks()->count())->toBe(2);
    expect($project->tasks->pluck('id')->all())->toContain($defaultA->id, $defaultB->id);
});
