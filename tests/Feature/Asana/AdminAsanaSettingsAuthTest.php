<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
