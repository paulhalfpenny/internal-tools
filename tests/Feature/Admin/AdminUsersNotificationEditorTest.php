<?php

use App\Enums\Role;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
