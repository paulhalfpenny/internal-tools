<?php

use App\Enums\Role;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can set notification fields and line manager', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $manager = User::factory()->create(['role' => Role::Manager, 'name' => 'Manager']);
    $employee = User::factory()->create(['role' => Role::User, 'weekly_capacity_hours' => 40]);

    $this->actingAs($admin);

    Livewire::test(AdminUsers::class)
        ->call('edit', $employee->id)
        ->set('editReportsToUserId', $manager->id)
        ->set('editNotificationsPausedUntil', '2026-06-01')
        ->set('editEmailNotificationsEnabled', false)
        ->set('editSlackNotificationsEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();
    expect($employee->reports_to_user_id)->toBe($manager->id);
    expect($employee->notifications_paused_until?->toDateString())->toBe('2026-06-01');
    expect($employee->email_notifications_enabled)->toBeFalse();
    expect($employee->slack_notifications_enabled)->toBeTrue();
});

test('manager dropdown excludes self and descendants', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $manager = User::factory()->create(['role' => Role::Manager, 'name' => 'Top Manager']);
    $subManager = User::factory()->create(['role' => Role::Manager, 'name' => 'Sub Manager', 'reports_to_user_id' => $manager->id]);
    $employee = User::factory()->create(['role' => Role::User, 'reports_to_user_id' => $subManager->id]);

    $this->actingAs($admin);

    $component = Livewire::test(AdminUsers::class)->call('edit', $manager->id);
    $candidates = $component->viewData('managerCandidates')->pluck('id')->all();

    expect($candidates)->not->toContain($manager->id);
    expect($candidates)->not->toContain($subManager->id);
    expect($candidates)->toContain($admin->id);
});

test('save rejects circular reporting line', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $manager = User::factory()->create(['role' => Role::Manager]);
    $employee = User::factory()->create(['role' => Role::User, 'reports_to_user_id' => $manager->id]);

    $this->actingAs($admin);

    Livewire::test(AdminUsers::class)
        ->call('edit', $manager->id)
        ->set('editReportsToUserId', $employee->id)
        ->call('save')
        ->assertHasErrors('editReportsToUserId');

    expect($manager->refresh()->reports_to_user_id)->toBeNull();
});

test('save rejects self as manager', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $employee = User::factory()->create(['role' => Role::User]);

    $this->actingAs($admin);

    Livewire::test(AdminUsers::class)
        ->call('edit', $employee->id)
        ->set('editReportsToUserId', $employee->id)
        ->call('save')
        ->assertHasErrors('editReportsToUserId');
});
