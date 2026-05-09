<?php

use App\Enums\Role;
use App\Livewire\Admin\Users\Index;
use App\Models\Rate;
use App\Models\User;
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
        ->assertSet('editIsActive', true)
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

test('admin cannot deactivate themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('editIsActive', false)
        ->set('editName', $admin->name)
        ->set('editWeeklyCapacity', '37.5')
        ->call('save')
        ->assertHasErrors(['editIsActive']);

    expect($admin->fresh()->is_active)->toBeTrue();
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
