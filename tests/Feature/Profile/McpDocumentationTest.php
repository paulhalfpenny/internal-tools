<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('guests cannot view the MCP documentation page', function () {
    expect(Route::has('profile.mcp'))->toBeTrue();

    $this->get(route('profile.mcp'))->assertRedirect(route('auth.login'));
});

test('authenticated users can view MCP setup documentation', function () {
    expect(Route::has('profile.mcp'))->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->get(route('profile.mcp'))
        ->assertOk()
        ->assertSeeText('How to connect via MCP')
        ->assertSeeText('Connect with Claude')
        ->assertSeeText('Connect with Codex')
        ->assertSeeText('What is available')
        ->assertSeeText('Suggested prompts')
        ->assertSeeText('codex mcp add filter_internal_tools')
        ->assertSeeText('codex mcp login filter_internal_tools')
        ->assertSeeText('mcp:use')
        ->assertSeeText('High-impact writes');
});

test('the user dropdown links to MCP documentation', function () {
    expect(Route::has('profile.mcp'))->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->get(route('profile.api-tokens'))
        ->assertOk()
        ->assertSee(route('profile.mcp'), false)
        ->assertSeeText('MCP guide');
});
