<?php

use App\Models\User;
use App\Services\Asana\AsanaTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.asana.client_id' => 'test-client',
        'services.asana.client_secret' => 'test-secret',
        'services.asana.redirect' => 'http://localhost/cb',
    ]);
});

test('returns valid token unchanged when not expired', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'still-good',
        'asana_refresh_token' => 'r',
        'asana_token_expires_at' => now()->addHour(),
    ]);

    expect((new AsanaTokenManager)->getValidToken($user))->toBe('still-good');
});

test('returns null when never connected', function () {
    $user = User::factory()->create();
    expect((new AsanaTokenManager)->getValidToken($user))->toBeNull();
});

test('refreshes when expired and stores new token', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'old',
        'asana_refresh_token' => 'r',
        'asana_token_expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response([
            'access_token' => 'fresh',
            'refresh_token' => 'r2',
            'expires_in' => 3600,
        ]),
    ]);

    $token = (new AsanaTokenManager)->getValidToken($user);
    expect($token)->toBe('fresh');

    $user->refresh();
    expect($user->asana_access_token)->toBe('fresh');
    expect($user->asana_refresh_token)->toBe('r2');
});

test('disconnects user when refresh fails', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'old',
        'asana_refresh_token' => 'r',
        'asana_token_expires_at' => now()->subMinute(),
        'asana_user_gid' => 'me',
    ]);

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $token = (new AsanaTokenManager)->getValidToken($user);
    expect($token)->toBeNull();

    $user->refresh();
    expect($user->asana_access_token)->toBeNull();
    expect($user->asana_user_gid)->toBeNull();
});
