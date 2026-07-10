<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('manager gets 403 from the Asana integration page', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);

    $this->actingAs($manager)
        ->get(route('admin.integrations.asana'))
        ->assertForbidden();
});
