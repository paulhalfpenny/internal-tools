<?php

use App\Enums\Role;
use App\Livewire\Admin\Projects\Edit;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('removeUser detaches the user from the project pivot', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $member = User::factory()->create();
    $project->users()->attach($member->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    Livewire::test(Edit::class, ['project' => $project])
        ->call('removeUser', $member->id);

    expect($project->fresh()->users()->where('users.id', $member->id)->exists())->toBeFalse();
});

test('confirmAddUsers attaches every queued user', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $a = User::factory()->create(['name' => 'Alice']);
    $b = User::factory()->create(['name' => 'Bob']);
    $c = User::factory()->create(['name' => 'Carol']);

    Livewire::test(Edit::class, ['project' => $project])
        ->call('openAddUserModal')
        ->set('pendingNewUserDropdown', $a->id)
        ->call('queuePendingUser')
        ->set('pendingNewUserDropdown', $b->id)
        ->call('queuePendingUser')
        ->set('pendingNewUserDropdown', $c->id)
        ->call('confirmAddUsers') // c is queued via the implicit-on-Save path
        ->assertSet('showAddUserModal', false);

    $attachedIds = $project->fresh()->users->pluck('id')->all();
    expect($attachedIds)->toContain($a->id, $b->id, $c->id);
});

test('confirmAddUsers ignores users already assigned to the project', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $existing = User::factory()->create();
    $project->users()->attach($existing->id, ['hourly_rate_override' => 50.00, 'rate_id' => null]);

    Livewire::test(Edit::class, ['project' => $project])
        ->call('openAddUserModal')
        ->set('pendingNewUserDropdown', $existing->id)
        ->call('confirmAddUsers');

    // Pivot row should still have the original 50.00 override, not be re-attached with null.
    $pivot = $project->fresh()->users()->where('users.id', $existing->id)->first()->pivot;
    expect((float) $pivot->hourly_rate_override)->toBe(50.00);
});

test('unqueuePendingUser removes a user from the queued list', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $a = User::factory()->create();
    $b = User::factory()->create();

    Livewire::test(Edit::class, ['project' => $project])
        ->call('openAddUserModal')
        ->set('pendingNewUserDropdown', $a->id)
        ->call('queuePendingUser')
        ->set('pendingNewUserDropdown', $b->id)
        ->call('queuePendingUser')
        ->call('unqueuePendingUser', $a->id)
        ->assertSet('pendingNewUserIds', [$b->id]);
});
