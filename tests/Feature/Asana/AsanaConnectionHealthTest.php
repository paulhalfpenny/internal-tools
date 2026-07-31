<?php

use App\Models\User;
use App\Notifications\AsanaConnectionLost;
use App\Services\Asana\AsanaTokenManager;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.asana.client_id' => 'test-client',
        'services.asana.client_secret' => 'test-secret',
        'services.asana.redirect' => 'http://localhost/integrations/asana/callback',
    ]);

    NotificationSettings::flushCache();
    NotificationSettings::setEmailEnabled(true);
    NotificationSettings::setSlackEnabled(true);
});

// --- Root cause: the reconnect that stranded the connection -------------------

test('callback keeps the stored refresh token when Asana omits one', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'old-access',
        'asana_refresh_token' => 'keep-me',
        'asana_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        // Asana omits refresh_token when re-authorising an already-authorised app.
        'app.asana.com/-/oauth_token' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 3600,
        ]),
        'app.asana.com/api/1.0/users/me*' => Http::response([
            'data' => ['gid' => 'me-1', 'name' => 'Pat', 'workspaces' => [['gid' => 'ws-1', 'name' => 'Acme']]],
        ]),
        'app.asana.com/api/1.0/projects*' => Http::response(['data' => [], 'next_page' => null]),
    ]);

    $this->actingAs($user)
        ->withSession(['asana_oauth_state' => 'st'])
        ->get(route('integrations.asana.callback', ['code' => 'c', 'state' => 'st']))
        ->assertRedirect(route('profile.asana'));

    $user->refresh();

    expect($user->asana_access_token)->toBe('new-access');
    expect($user->asana_refresh_token)->toBe('keep-me');
});

test('a failed profile fetch does not half-write the connection', function () {
    $user = User::factory()->create([
        'asana_access_token' => null,
        'asana_refresh_token' => null,
    ]);

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ]),
        'app.asana.com/api/1.0/users/me*' => Http::response(['error' => 'boom'], 500),
    ]);

    $this->actingAs($user)
        ->withSession(['asana_oauth_state' => 'st'])
        ->get(route('integrations.asana.callback', ['code' => 'c', 'state' => 'st']))
        ->assertSessionHas('asana_error');

    $user->refresh();

    // Never left holding an access token with no usable profile behind it.
    expect($user->asana_access_token)->toBeNull();
    expect($user->asana_user_gid)->toBeNull();
});

test('reconnecting clears a recorded drop', function () {
    $user = User::factory()->create([
        'asana_connection_lost_at' => now()->subDay(),
        'asana_connection_alerted_at' => now()->subDay(),
    ]);

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600,
        ]),
        'app.asana.com/api/1.0/users/me*' => Http::response([
            'data' => ['gid' => 'me-1', 'name' => 'Pat', 'workspaces' => [['gid' => 'ws-1', 'name' => 'Acme']]],
        ]),
        'app.asana.com/api/1.0/projects*' => Http::response(['data' => [], 'next_page' => null]),
    ]);

    $this->actingAs($user)
        ->withSession(['asana_oauth_state' => 'st'])
        ->get(route('integrations.asana.callback', ['code' => 'c', 'state' => 'st']));

    $user->refresh();

    expect($user->asana_connection_lost_at)->toBeNull();
    expect($user->asana_connection_alerted_at)->toBeNull();
});

// --- Recording the drop -------------------------------------------------------

test('a rejected refresh records the drop', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'old',
        'asana_refresh_token' => 'r',
        'asana_token_expires_at' => now()->subMinute(),
        'asana_user_gid' => 'me',
    ]);

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect((new AsanaTokenManager)->getValidToken($user))->toBeNull();

    $user->refresh();
    expect($user->asana_access_token)->toBeNull();
    expect($user->asana_connection_lost_at)->not->toBeNull();
});

test('an expired token with no refresh token records the drop', function () {
    // Exactly the state a pre-fix reconnect left behind.
    $user = User::factory()->create([
        'asana_access_token' => 'stranded',
        'asana_refresh_token' => null,
        'asana_token_expires_at' => now()->subMinute(),
    ]);

    expect((new AsanaTokenManager)->getValidToken($user))->toBeNull();

    $user->refresh();
    expect($user->asana_connection_lost_at)->not->toBeNull();
});

test('the recorded drop time does not move on repeated failures', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'stranded',
        'asana_refresh_token' => null,
        'asana_token_expires_at' => now()->subMinute(),
    ]);

    $manager = new AsanaTokenManager;
    $manager->getValidToken($user);
    $first = $user->fresh()->asana_connection_lost_at;

    $this->travel(2)->hours();
    $manager->getValidToken($user->fresh());

    expect($user->fresh()->asana_connection_lost_at->timestamp)->toBe($first->timestamp);
});

test('disconnecting clears the health stamps', function () {
    $user = User::factory()->create([
        'asana_access_token' => 'a',
        'asana_connection_lost_at' => now(),
        'asana_connection_alerted_at' => now(),
    ]);

    (new AsanaTokenManager)->disconnect($user);

    $user->refresh();
    expect($user->asana_connection_lost_at)->toBeNull();
    expect($user->asana_connection_alerted_at)->toBeNull();
});

// --- The daily check ----------------------------------------------------------

test('the check notifies a user whose connection dropped', function () {
    Notification::fake();

    $user = User::factory()->create([
        'is_active' => true,
        'email_notifications_enabled' => true,
        'asana_connection_lost_at' => now()->subHour(),
    ]);

    $this->artisan('asana:check-connections')->assertSuccessful();

    Notification::assertSentTo($user, AsanaConnectionLost::class);
    expect($user->fresh()->asana_connection_alerted_at)->not->toBeNull();
});

test('the check finds a stranded connection that no job has touched', function () {
    Notification::fake();

    $user = User::factory()->create([
        'is_active' => true,
        'email_notifications_enabled' => true,
        'asana_access_token' => 'stranded',
        'asana_refresh_token' => null,
        'asana_token_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('asana:check-connections')->assertSuccessful();

    Notification::assertSentTo($user, AsanaConnectionLost::class);
});

test('the check does not notify twice for the same drop', function () {
    Notification::fake();

    $user = User::factory()->create([
        'is_active' => true,
        'asana_connection_lost_at' => now()->subDay(),
        'asana_connection_alerted_at' => now()->subHour(),
    ]);

    $this->artisan('asana:check-connections')->assertSuccessful();

    Notification::assertNotSentTo($user, AsanaConnectionLost::class);
});

test('the check leaves healthy connections alone', function () {
    Notification::fake();

    $user = User::factory()->create([
        'is_active' => true,
        'asana_access_token' => 'good',
        'asana_refresh_token' => 'r',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'me',
    ]);

    $this->artisan('asana:check-connections')->assertSuccessful();

    Notification::assertNotSentTo($user, AsanaConnectionLost::class);
    expect($user->fresh()->asana_connection_lost_at)->toBeNull();
});

// Notification::fake() never renders the view, so a broken Blade template would
// otherwise only surface when a real alert went out.
test('the alert email renders with and without a known drop time', function () {
    $user = User::factory()->create(['name' => 'Pat Tester']);

    foreach ([now()->subDay(), null] as $lostAt) {
        $html = (string) (new AsanaConnectionLost($lostAt))->toMail($user)->render();

        expect($html)->toContain('Reconnect Asana');
        expect($html)->toContain('Pat');
        expect(str_contains($html, 'It stopped working'))->toBe($lostAt !== null);
    }
});

test('dry run reports without notifying', function () {
    Notification::fake();

    $user = User::factory()->create([
        'is_active' => true,
        'asana_connection_lost_at' => now()->subHour(),
    ]);

    $this->artisan('asana:check-connections', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect($user->fresh()->asana_connection_alerted_at)->toBeNull();
});
