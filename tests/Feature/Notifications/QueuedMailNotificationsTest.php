<?php

use App\Models\User;
use App\Notifications\ManagerWeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Env;

test('queued mail notification limiter defaults to five sends per second', function () {
    $environment = Env::getRepository();
    $environmentValue = $environment->get('MAIL_QUEUE_RATE_LIMIT_PER_SECOND');
    $configuredValue = config('mail.queued_notifications.rate_limit_per_second');

    $environment->clear('MAIL_QUEUE_RATE_LIMIT_PER_SECOND');

    try {
        $mailConfig = require config_path('mail.php');
        config(['mail.queued_notifications.rate_limit_per_second' => $mailConfig['queued_notifications']['rate_limit_per_second']]);

        $limiter = app(CacheRateLimiter::class)->limiter('queued-mail-notifications');

        expect($limiter)->not->toBeNull();

        $limit = $limiter(new SendQueuedNotifications(
            new User,
            new ManagerWeeklyDigest([], CarbonImmutable::parse('2026-07-06')),
            ['mail'],
        ));
    } finally {
        config(['mail.queued_notifications.rate_limit_per_second' => $configuredValue]);

        if ($environmentValue === null) {
            $environment->clear('MAIL_QUEUE_RATE_LIMIT_PER_SECOND');
        } else {
            $environment->set('MAIL_QUEUE_RATE_LIMIT_PER_SECOND', $environmentValue);
        }
    }

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(5)
        ->and($limit->decaySeconds)->toBe(1)
        ->and($limit->key)->toBe('resend');
});
