<?php

use App\Models\Project;
use App\Models\User;
use App\Notifications\BudgetThresholdReached;
use App\Notifications\ManagerWeeklyDigest;
use App\Notifications\MidWeekTimesheetNudge;
use App\Notifications\MonthlyTimesheetOverdue;
use App\Notifications\WeeklyTimesheetOverdue;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Middleware\RateLimited;

afterEach(fn () => CarbonImmutable::setTestNow());

function queuedMailRateLimiterName(RateLimited $middleware): string
{
    $property = new ReflectionProperty($middleware, 'limiterName');
    $property->setAccessible(true);

    return $property->getValue($middleware);
}

function queuedMailNotifications(): array
{
    $weekStart = CarbonImmutable::parse('2026-07-06');

    return [
        'budget threshold' => [
            new BudgetThresholdReached(
                project: new Project,
                threshold: 80,
                periodKey: 'lifetime',
                percentUsed: 81.5,
                budgetAmount: 1000,
                actualAmount: 815,
            ),
        ],
        'mid-week nudge' => [new MidWeekTimesheetNudge(12, 40, 20, $weekStart)],
        'weekly overdue' => [new WeeklyTimesheetOverdue(30, 40, $weekStart)],
        'monthly overdue' => [new MonthlyTimesheetOverdue(120, 160, $weekStart->startOfMonth())],
        'manager digest' => [
            new ManagerWeeklyDigest([
                ['name' => 'Pat Example', 'email' => 'pat@example.com', 'hours' => 12.5, 'target' => 40.0],
            ], $weekStart),
        ],
    ];
}

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

test('queued mail notifications throttle mail and expose retry metadata', function (Notification $notification) {
    CarbonImmutable::setTestNow('2026-07-08 07:01:34');
    config([
        'mail.queued_notifications.backoff' => [30, 120, 300],
        'mail.queued_notifications.retry_for_minutes' => 45,
    ]);

    $middleware = $notification->middleware(new User, 'mail');

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class)
        ->and(queuedMailRateLimiterName($middleware[0]))->toBe('queued-mail-notifications')
        ->and($notification->middleware(new User, 'slack'))->toBe([])
        ->and($notification->backoff())->toBe([30, 120, 300])
        ->and($notification->retryUntil()->toDateTimeString())->toBe('2026-07-08 07:46:34');
})->with('queued mail notifications');

dataset('queued mail notifications', queuedMailNotifications());
