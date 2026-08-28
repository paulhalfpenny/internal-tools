<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('asanaSyncActor returns the flagged user or null', function () {
    expect(User::asanaSyncActor())->toBeNull();

    $bot = User::factory()->create();
    User::designateAsanaSyncActor($bot);

    expect(User::asanaSyncActor()?->id)->toBe($bot->id);
});

test('designating a sync actor clears the previous one', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    User::designateAsanaSyncActor($first);
    User::designateAsanaSyncActor($second);

    expect(User::query()->where('is_asana_sync_actor', true)->pluck('id')->all())
        ->toBe([$second->id]);
});

test('re-designating the current actor keeps exactly one', function () {
    $bot = User::factory()->create();
    User::designateAsanaSyncActor($bot);
    User::designateAsanaSyncActor($bot->fresh());

    expect(User::asanaSyncActor()?->id)->toBe($bot->id)
        ->and(User::query()->where('is_asana_sync_actor', true)->count())->toBe(1);
});

test('designating null clears any existing sync actor', function () {
    $bot = User::factory()->create();
    User::designateAsanaSyncActor($bot);

    User::designateAsanaSyncActor(null);

    expect(User::asanaSyncActor())->toBeNull();
});
