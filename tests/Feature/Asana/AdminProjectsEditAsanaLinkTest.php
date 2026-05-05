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
        ->assertSet('asanaProjectGid', '');
});

test('linking to Asana project ensures custom field and dispatches task pull', function () {
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
        ->set('asanaProjectGid', 'AP1')
        ->call('save');

    $project->refresh();
    expect($project->asana_project_gid)->toBe('AP1');
    expect($project->asana_workspace_gid)->toBe('WS1');
    expect($project->asana_custom_field_gid)->toBe('CF1');

    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP1');
});

test('clearing the Asana link blanks out the related fields', function () {
    config(['services.asana.custom_field_name' => 'Hours tracked (Internal Tools)']);

    $admin = asanaTestAdminWithAsana();
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana A', 'is_archived' => false]);
    $project = Project::factory()->create([
        'asana_project_gid' => 'AP1',
        'asana_workspace_gid' => 'WS1',
        'asana_custom_field_gid' => 'CF1',
    ]);

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['project' => $project])
        ->set('asanaProjectGid', '')
        ->call('save');

    $project->refresh();
    expect($project->asana_project_gid)->toBeNull();
    expect($project->asana_workspace_gid)->toBeNull();
    expect($project->asana_custom_field_gid)->toBeNull();
});
