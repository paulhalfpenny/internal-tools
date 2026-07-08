<?php

namespace App\Notifications\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;

trait ThrottlesQueuedMail
{
    /**
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        if ($channel !== 'mail') {
            return [];
        }

        return [new RateLimited('queued-mail-notifications')];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('mail.queued_notifications.backoff', [30, 120, 300]);
        $seconds = is_array($backoff) ? $backoff : [$backoff];

        return array_map(
            static fn (mixed $delay): int => max(1, (int) $delay),
            array_values($seconds),
        );
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(
            max(1, (int) config('mail.queued_notifications.retry_for_minutes', 30)),
        );
    }
}
