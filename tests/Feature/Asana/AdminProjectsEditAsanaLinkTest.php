<?php

use App\Enums\Role;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Livewire\Admin\Projects\Edit;
use App\Models\AsanaProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function asanaTestAdminWithAsana(): User
{
    return User::factory()->create([
        'role' => Role::Admin,
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'admin',
        'asana_workspace_gid' => 'WS1',
    ]);
}

test('shows Asana picker when admin is connected and projects are cached', function () {
    config(['services.asana.custom_field_name' => 'Hours tracked (Internal Tools)']);

    $admin = asanaTestAdminWithAsana();
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana Project A', 'is_archived' => false]);
    $project = Project::factory()->create();

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['project' => $project])
        ->assertSee('Asana Project A')
        ->assertSet('asanaProjectGids', []);
});

test('linking to an Asana board ensures custom field and dispatches task pull', function () {
    config([
        'services.asana.client_id' => 'c',
        'services.asana.client_secret' => 's',
        'services.asana.redirect' => 'http://localhost/cb',
        'services.asana.custom_field_name' => 'Hours tracked (Internal Tools)',
    ]);

    Bus::fake([PullAsanaTasksJob::class]);
    Http::fake([
        'app.asana.com/api/1.0/projects/AP1/custom_field_settings*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/workspaces/WS1/custom_fields*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/custom_fields' => Http::response(['data' => ['gid' => 'CF1']]),
        'app.asana.com/api/1.0/projects/AP1/addCustomFieldSetting' => Http::response(['data' => ['gid' => 'S1']]),
    ]);

    $admin = asanaTestAdminWithAsana();
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana A', 'is_archived' => false]);
    $project = Project::factory()->create();

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['project' => $project])
        ->set('asanaProjectGids', ['AP1'])
        ->call('save');

    $project->refresh();
    $linked = $project->asanaProjects()->get();
    expect($linked->pluck('gid')->all())->toBe(['AP1']);
    expect($linked->first()->getRelation('pivot')->getAttribute('asana_custom_field_gid'))->toBe('CF1');

    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP1');
});

test('linking to multiple Asana boards persists every link and dispatches a pull per board', function () {
    config([
        'services.asana.client_id' => 'c',
        'services.asana.client_secret' => 's',
        'services.asana.redirect' => 'http://localhost/cb',
        'services.asana.custom_field_name' => 'Hours tracked (Internal Tools)',
    ]);

    Bus::fake([PullAsanaTasksJob::class]);
    Http::fake([
        'app.asana.com/api/1.0/projects/AP1/custom_field_settings*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/projects/AP2/custom_field_settings*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/workspaces/WS1/custom_fields*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/custom_fields' => Http::sequence()
            ->push(['data' => ['gid' => 'CF1']])
            ->push(['data' => ['gid' => 'CF2']]),
        'app.asana.com/api/1.0/projects/AP1/addCustomFieldSetting' => Http::response(['data' => ['gid' => 'S1']]),
        'app.asana.com/api/1.0/projects/AP2/addCustomFieldSetting' => Http::response(['data' => ['gid' => 'S2']]),
    ]);

    $admin = asanaTestAdminWithAsana();
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana A', 'is_archived' => false]);
    AsanaProject::create(['gid' => 'AP2', 'workspace_gid' => 'WS1', 'name' => 'Asana B', 'is_archived' => false]);
    $project = Project::factory()->create();

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['project' => $project])
        ->set('asanaProjectGids', ['AP1', 'AP2'])
        ->call('save');

    $project->refresh();
    expect($project->asanaProjects()->pluck('gid')->sort()->values()->all())->toBe(['AP1', 'AP2']);

    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP1');
    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP2');
});

test('asana_task_required toggle persists from the project edit form', function () {
    config(['services.asana.custom_field_name' => 'Hours tracked (Internal Tools)']);

    $admin = asanaTestAdminWithAsana();
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana A', 'is_archived' => false]);
    $project = Project::factory()->create();
    $project->asanaProjects()->attach('AP1', ['asana_custom_field_gid' => 'CF1']);

    $this->actingAs($admin);

    // Defaults to true after the migration's column default.
    expect($project->fresh()->asana_task_required)->toBeTrue();

    Livewire::test(Edit::class, ['project' => $project])
        ->assertSet('asanaTaskRequired', true)
        ->set('asanaTaskRequired', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()->asana_task_required)->toBeFalse();
});

test('unselecting a previously linked board detaches the pivot row', function () {
    config(['services.asana.custom_field_name' => 'Hours tracked (Internal Tools)']);

    $admin = asanaTestAdminWithAsana();
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana A', 'is_archived' => false]);
    $project = Project::factory()->create();
    $project->asanaProjects()->attach('AP1', ['asana_custom_field_gid' => 'CF1']);

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['project' => $project])
        ->set('asanaProjectGids', [])
        ->call('save');

    expect($project->fresh()->asanaProjects()->count())->toBe(0);
});
