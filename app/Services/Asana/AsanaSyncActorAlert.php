<?php

namespace App\Services\Asana;

use App\Models\User;
use App\Notifications\AsanaSyncActorUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

final class AsanaSyncActorAlert
{
    private const CACHE_KEY = 'asana:sync-hours:actor-unavailable-alerted';

    public function reportUnavailable(?User $actor, string $reason): bool
    {
        if (! Cache::add(self::CACHE_KEY, true, now()->addYear())) {
            return false;
        }

        Notification::route('mail', (string) config('services.asana.sync_alert_email'))
            ->notify(new AsanaSyncActorUnavailable(
                actorName: $actor?->name,
                actorEmail: $actor?->email,
                reason: $reason,
            ));

        return true;
    }

    public function resolve(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
