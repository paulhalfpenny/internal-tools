<?php

use App\Models\User;
use App\Notifications\ManagerWeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\SendQueuedNotifications;

test('queued mail notification limiter stays below the Resend per-second cap', function () {
    config(['mail.queued_notifications.rate_limit_per_second' => 9]);

    $limiter = app(CacheRateLimiter::class)->limiter('queued-mail-notifications');

    expect($limiter)->not->toBeNull();

    $limit = $limiter(new SendQueuedNotifications(
        new User,
        new ManagerWeeklyDigest([], CarbonImmutable::parse('2026-07-06')),
        ['mail'],
    ));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(9)
        ->and($limit->decaySeconds)->toBe(1)
        ->and($limit->key)->toBe('resend');
});
