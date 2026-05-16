<?php

use App\Enums\Role;
use App\Livewire\Admin\Teams\Index as TeamsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admin cannot access teams admin screen', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);

    $this->actingAs($manager)->get(route('admin.teams'))->assertForbidden();
});

test('admin can create edit archive and delete teams', function () {
    $admin = User::factory()->admin()->create();
    $developer = User::factory()->create(['name' => 'Dev Member']);
    $designer = User::factory()->create(['name' => 'Design Member']);

    Livewire::actingAs($admin)
        ->test(TeamsIndex::class)
        ->set('name', 'Development')
        ->set('description', 'People building things')
        ->set('colour', '#0E8F3A')
        ->call('addUser', $developer->id)
        ->call('create')
        ->assertHasNoErrors();

    $team = Team::where('name', 'Development')->firstOrFail();
    expect($team->name)->toBe('Development');
    expect($team->description)->toBe('People building things');
    expect($team->colour)->toBe('#0E8F3A');
    expect($team->users()->pluck('users.id')->all())->toBe([$developer->id]);

    Livewire::actingAs($admin)
        ->test(TeamsIndex::class)
        ->call('edit', $team->id)
        ->set('editName', 'Engineering')
        ->set('editDescription', 'Build team')
        ->set('editColour', '#2563EB')
        ->call('addEditUser', $designer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->name)->toBe('Engineering');
    expect($team->fresh()->users()->pluck('users.id')->all())->toEqualCanonicalizing([
        $developer->id,
        $designer->id,
    ]);

    Livewire::actingAs($admin)
        ->test(TeamsIndex::class)
        ->call('toggleArchive', $team->id);

    expect($team->fresh()->is_archived)->toBeTrue();

    Livewire::actingAs($admin)
        ->test(TeamsIndex::class)
        ->call('delete', $team->id);

    $this->assertDatabaseMissing('teams', ['id' => $team->id]);
});

test('admin can assign a user to multiple teams from the user editor', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $development = Team::factory()->create(['name' => 'Development']);
    $design = Team::factory()->create(['name' => 'Design']);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('edit', $user->id)
        ->set('editTeamIds.0', $development->id)
        ->call('addEditTeamRow')
        ->set('editTeamIds.1', $design->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->teams()->pluck('teams.name')->all())->toEqualCanonicalizing([
        'Development',
        'Design',
    ]);
});

test('team member picker exposes all matching active users', function () {
    $admin = User::factory()->admin()->create();
    $team = Team::factory()->create(['name' => 'Delivery']);

    $users = collect(range(1, 12))
        ->map(fn (int $number) => User::factory()->create([
            'name' => sprintf('Available Person %02d', $number),
        ]));

    $component = Livewire::actingAs($admin)
        ->test(TeamsIndex::class)
        ->call('edit', $team->id);

    $availableUserIds = $component->viewData('availableEditUsers')->pluck('id')->all();

    expect($availableUserIds)->toHaveCount(13);
    expect($availableUserIds)->toEqualCanonicalizing([
        $admin->id,
        ...$users->pluck('id')->all(),
    ]);
});
