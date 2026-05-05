<?php

use App\Models\AsanaWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.asana.client_id' => 'test-client',
        'services.asana.client_secret' => 'test-secret',
        'services.asana.redirect' => 'http://localhost/integrations/asana/callback',
    ]);
});

test('redirect sends user to asana with stored state', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([])
        ->get(route('integrations.asana.redirect'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://app.asana.com/-/oauth_authorize');
    expect($location)->toContain('client_id=test-client');
    expect($location)->toContain('response_type=code');
});

test('callback exchanges code for tokens, captures profile, and stores workspace', function () {
    $user = User::factory()->create();

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ]),
        'app.asana.com/api/1.0/users/me*' => Http::response([
            'data' => [
                'gid' => 'me-99',
                'name' => 'Pat Tester',
                'email' => 'pat@example.com',
                'workspaces' => [
                    ['gid' => 'ws-1', 'name' => 'Acme'],
                    ['gid' => 'ws-2', 'name' => 'Beta'],
                ],
            ],
        ]),
        'app.asana.com/api/1.0/projects*' => Http::response(['data' => [], 'next_page' => null]),
    ]);

    $this->actingAs($user)
        ->withSession(['asana_oauth_state' => 'expected-state'])
        ->get(route('integrations.asana.callback', ['code' => 'authcode', 'state' => 'expected-state']))
        ->assertRedirect(route('profile.asana'));

    $user->refresh();

    expect($user->asana_access_token)->toBe('access-1');
    expect($user->asana_refresh_token)->toBe('refresh-1');
    expect($user->asana_user_gid)->toBe('me-99');
    expect($user->asana_workspace_gid)->toBe('ws-1');
    expect(AsanaWorkspace::count())->toBe(2);
});

test('callback rejects state mismatch', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['asana_oauth_state' => 'real'])
        ->get(route('integrations.asana.callback', ['code' => 'x', 'state' => 'fake']))
        ->assertRedirect(route('profile.asana'))
        ->assertSessionHas('asana_error');

    expect($user->fresh()->asana_access_token)->toBeNull();
});

test('disconnect clears tokens', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'a',
        'asana_refresh_token' => 'b',
        'asana_user_gid' => 'me-1',
        'asana_workspace_gid' => 'ws-1',
    ]);

    $this->actingAs($user)
        ->post(route('integrations.asana.disconnect'))
        ->assertRedirect(route('profile.asana'));

    $user->refresh();
    expect($user->asana_access_token)->toBeNull();
    expect($user->asana_user_gid)->toBeNull();
});
