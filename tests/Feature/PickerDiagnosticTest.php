<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('picker diagnostic endpoint rejects guests', function () {
    $this->postJson(route('diagnostics.picker'), ['kind' => 'task-picker-dead-click'])
        ->assertUnauthorized();
});

test('picker diagnostic requires a kind', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('diagnostics.picker'), [])
        ->assertStatus(422);
});

test('authenticated user can record a picker diagnostic', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('diagnostics.picker'), [
            'kind' => 'task-picker-dead-click',
            'clickedId' => '3',
            'committed' => '',
            'morphCount' => 2,
            'msSinceLoad' => 5000,
        ])
        ->assertNoContent();
});
