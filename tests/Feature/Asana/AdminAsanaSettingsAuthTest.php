<?php

use App\Enums\Role;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Livewire\Admin\Integrations\AsanaSettings;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaHoursSyncRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can access the Asana integration page', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.integrations.asana'))
        ->assertOk();
});

test('regular user gets 403 from the Asana integration page', function () {
    $user = User::factory()->create(['role' => Role::User]);

    $this->actingAs($user)
        ->get(route('admin.integrations.asana'))
        ->assertForbidden();
});

test('manager gets 403 from the Asana integration page', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);

    $this->actingAs($manager)
        ->get(route('admin.integrations.asana'))
        ->assertForbidden();
});

test('guest is redirected to login from the Asana integration page', function () {
    $this->get(route('admin.integrations.asana'))->assertRedirect(route('auth.login'));
});

test('admin can designate a connected account and retry pending hours', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => Role::Admin]);
    $bot = User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    TimeEntry::factory()->make([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'asana_task_gid' => 'T-PENDING',
        'asana_sync_error' => 'Waiting for the designated account.',
        'asana_sync_error_code' => TimeEntry::ASANA_SYNC_ERROR_ACTOR_UNAVAILABLE,
    ])->saveQuietly();
    app(AsanaHoursSyncRecovery::class)->markPending(
        'T-PENDING',
        $project->id,
        'actor_no_token',
    );

    $this->actingAs($admin);
    Livewire::test(AsanaSettings::class)
        ->set('syncActorUserId', $bot->id);

    expect(User::asanaSyncActor()?->id)->toBe($bot->id);
    Queue::assertPushed(
        SyncAsanaTaskHoursJob::class,
        fn (SyncAsanaTaskHoursJob $job) => $job->asanaTaskGid === 'T-PENDING'
            && $job->projectId === $project->id,
    );
});

test('settings explain that no actor pauses hours instead of falling back', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.integrations.asana'))
        ->assertSee('None (hours sync paused)')
        ->assertDontSee('fall back to an admin');
});

test('settings keep a disconnected designated actor visible and warn that hours are pending', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $bot = User::factory()->create([
        'name' => 'Internal Tools Bot',
        'asana_access_token' => null,
        'asana_user_gid' => null,
    ]);
    User::designateAsanaSyncActor($bot);

    $this->actingAs($admin)
        ->get(route('admin.integrations.asana'))
        ->assertSee('Internal Tools Bot')
        ->assertSee('disconnected')
        ->assertSee('hours are pending');
});

test('settings keep the pending warning visible when the designated actor has reconnected', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $bot = User::factory()->create([
        'asana_access_token' => 'token',
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::designateAsanaSyncActor($bot);
    $entry = TimeEntry::factory()->make([
        'asana_task_gid' => 'T-PENDING',
        'asana_sync_error' => 'Waiting for the designated account.',
        'asana_sync_error_code' => TimeEntry::ASANA_SYNC_ERROR_ACTOR_UNAVAILABLE,
    ]);
    $entry->saveQuietly();
    app(AsanaHoursSyncRecovery::class)->markPending(
        'T-PENDING',
        $entry->project_id,
        'actor_no_token',
    );

    $this->actingAs($admin)
        ->get(route('admin.integrations.asana'))
        ->assertSee('1 task total is pending');
});
