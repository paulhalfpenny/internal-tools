<?php

use App\Enums\Role;
use App\Livewire\Admin\Users\Index;
use App\Models\Client;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admin cannot access users admin screen', function () {
    $user = User::factory()->create(['role' => Role::User]);

    $this->actingAs($user)->get(route('admin.users'))->assertForbidden();
});

test('manager cannot access users admin screen', function () {
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)->get(route('admin.users'))->assertForbidden();
});

test('admin can access users admin screen', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.users'))->assertOk();
});

test('edit sets editingId and populates fields', function () {
    $admin = User::factory()->admin()->create();
    $rate = Rate::create(['name' => 'Designer', 'hourly_rate' => 55.00]);
    $other = User::factory()->create([
        'role' => Role::User,
        'role_title' => 'Designer',
        'rate_id' => $rate->id,
        'weekly_capacity_hours' => 37.5,
        'is_active' => true,
        'is_contractor' => false,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->assertSet('editingId', $other->id)
        ->assertSet('editRole', 'user')
        ->assertSet('editRoleTitle', 'Designer')
        ->assertSet('editRateId', $rate->id)
        ->assertSet('editWeeklyCapacity', '37.50')
        ->assertSet('editIsContractor', false);
});

test('cancel clears editingId', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->call('cancel')
        ->assertSet('editingId', null);
});

test('admin can change another user role', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['role' => Role::User]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->set('editRole', 'manager')
        ->set('editName', $other->name)
        ->set('editWeeklyCapacity', '37.5')
        ->call('save')
        ->assertSet('editingId', null)
        ->assertHasNoErrors();

    expect($other->fresh()->role)->toBe(Role::Manager);
});

test('admin cannot change their own role', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('editRole', 'user')
        ->set('editName', $admin->name)
        ->set('editWeeklyCapacity', '37.5')
        ->call('save')
        ->assertHasErrors(['editRole']);

    expect($admin->fresh()->role)->toBe(Role::Admin);
});

test('admin cannot archive themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmArchive', $admin->id)
        ->assertSet('confirmingArchiveId', null);

    expect($admin->fresh()->is_active)->toBeTrue();
    expect($admin->fresh()->archived_at)->toBeNull();
});

test('capacity must be between 0 and 168', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->set('editWeeklyCapacity', '200')
        ->set('editName', $other->name)
        ->call('save')
        ->assertHasErrors(['editWeeklyCapacity']);
});

test('capacity cannot be negative', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->set('editWeeklyCapacity', '-1')
        ->set('editName', $other->name)
        ->call('save')
        ->assertHasErrors(['editWeeklyCapacity']);
});

test('archive flow deactivates user, sets archived_at, and preserves time entries', function () {
    $admin = User::factory()->admin()->create();
    $leaver = User::factory()->create(['is_active' => true]);

    $client = Client::create(['name' => 'Acme', 'is_archived' => false]);
    $project = Project::create([
        'client_id' => $client->id,
        'name' => 'Site rebuild',
        'is_active' => true,
        'is_billable' => true,
    ]);
    $task = Task::create(['name' => 'Design', 'is_archived' => false]);
    $project->tasks()->attach($task->id, ['is_billable' => true]);

    $entry = TimeEntry::create([
        'user_id' => $leaver->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 2.5,
        'is_running' => false,
        'is_billable' => true,
        'billable_amount' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmArchive', $leaver->id)
        ->assertSet('confirmingArchiveId', $leaver->id)
        ->call('archive')
        ->assertSet('confirmingArchiveId', null);

    $leaver->refresh();
    expect($leaver->is_active)->toBeFalse();
    expect($leaver->archived_at)->not->toBeNull();
    expect($leaver->isArchived())->toBeTrue();

    expect(TimeEntry::find($entry->id))->not->toBeNull();
    expect(TimeEntry::find($entry->id)->hours)->toEqual(2.5);
});

test('unarchive reactivates user and clears archived_at', function () {
    $admin = User::factory()->admin()->create();
    $leaver = User::factory()->create(['is_active' => false]);
    $leaver->forceFill(['archived_at' => now()->subMonth()])->save();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('unarchive', $leaver->id);

    $leaver->refresh();
    expect($leaver->is_active)->toBeTrue();
    expect($leaver->archived_at)->toBeNull();
});

test('archived users are hidden by default in the index list', function () {
    $admin = User::factory()->admin()->create();
    $active = User::factory()->create(['name' => 'Active Person', 'is_active' => true]);
    $archived = User::factory()->create(['name' => 'Gone Person', 'is_active' => false]);
    $archived->forceFill(['archived_at' => now()])->save();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Active Person')
        ->assertDontSee('Gone Person')
        ->set('showArchived', true)
        ->assertSee('Active Person')
        ->assertSee('Gone Person');
});

test('hard delete of user with time entries is blocked by FK restriction', function () {
    $user = User::factory()->create();
    $client = Client::create(['name' => 'Acme', 'is_archived' => false]);
    $project = Project::create([
        'client_id' => $client->id,
        'name' => 'Site rebuild',
        'is_active' => true,
        'is_billable' => true,
    ]);
    $task = Task::create(['name' => 'Design', 'is_archived' => false]);
    $project->tasks()->attach($task->id, ['is_billable' => true]);
    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_amount' => 0,
    ]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});
