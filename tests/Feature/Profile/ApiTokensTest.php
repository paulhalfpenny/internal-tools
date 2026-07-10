<?php

use App\Enums\Role;
use App\Livewire\Profile\ApiTokens;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('user cannot revoke another user token', function () {
    $owner = User::factory()->create(['role' => Role::User]);
    $other = User::factory()->create(['role' => Role::User]);
    $token = PersonalAccessToken::generate($owner, 'mine')['model'];
    $this->actingAs($other);

    Livewire::test(ApiTokens::class)->call('revoke', $token->id);

    expect($token->fresh()->revoked_at)->toBeNull();
});
