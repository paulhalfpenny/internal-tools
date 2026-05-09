<?php

use App\Enums\Role;
use App\Livewire\Profile\ApiTokens;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('user can generate a token and see it once', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    Livewire::test(ApiTokens::class)
        ->set('newTokenName', 'Freshdesk widget')
        ->call('generate')
        ->assertSet('justIssuedName', 'Freshdesk widget')
        ->assertSeeText('Freshdesk widget');

    expect(PersonalAccessToken::where('user_id', $user->id)->count())->toBe(1);
});

test('generate is rejected without a name', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    Livewire::test(ApiTokens::class)
        ->set('newTokenName', '   ')
        ->call('generate')
        ->assertHasErrors(['newTokenName']);

    expect(PersonalAccessToken::count())->toBe(0);
});

test('user can revoke their own token', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $token = PersonalAccessToken::generate($user, 'old')['model'];
    $this->actingAs($user);

    Livewire::test(ApiTokens::class)->call('revoke', $token->id);

    expect($token->fresh()->revoked_at)->not->toBeNull();
});

test('user cannot revoke another user token', function () {
    $owner = User::factory()->create(['role' => Role::User]);
    $other = User::factory()->create(['role' => Role::User]);
    $token = PersonalAccessToken::generate($owner, 'mine')['model'];
    $this->actingAs($other);

    Livewire::test(ApiTokens::class)->call('revoke', $token->id);

    expect($token->fresh()->revoked_at)->toBeNull();
});
