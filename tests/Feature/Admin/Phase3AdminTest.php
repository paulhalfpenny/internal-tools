<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('managers can access the admin projects area but not other admin areas', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $regularUser = User::factory()->create(['role' => Role::User]);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $this->actingAs($manager);

    $this->get(route('admin.projects'))->assertOk();
    $this->get(route('admin.projects.edit', $project))->assertOk();
    $this->get(route('admin.users'))->assertForbidden();

    $this->get(route('timesheet'))
        ->assertSee('Admin')
        ->assertSee('href="'.route('admin.projects').'"', false)
        ->assertDontSee('href="'.route('admin.users').'"', false);

    $this->actingAs($regularUser)
        ->get(route('admin.projects'))
        ->assertForbidden();
});
