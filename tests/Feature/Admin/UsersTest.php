<?php

use App\Enums\Role;
use App\Livewire\Admin\Users\Index;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
