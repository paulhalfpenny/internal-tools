<?php

use App\Enums\Role;
use App\Livewire\Admin\Projects\Edit;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
