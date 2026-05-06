<?php

namespace App\Settings;

use Illuminate\Support\Facades\DB;

class NotificationSettings
{
    public const KEY_EMAIL = 'notifications.email_enabled';

    public const KEY_SLACK = 'notifications.slack_enabled';

    /** @var array<string, bool>|null */
    private static ?array $cache = null;

    public static function emailEnabled(): bool
    {
        return self::get(self::KEY_EMAIL);
    }

    public static function slackEnabled(): bool
    {
        return self::get(self::KEY_SLACK);
    }

    public static function setEmailEnabled(bool $value): void
    {
        self::set(self::KEY_EMAIL, $value);
    }

    public static function setSlackEnabled(bool $value): void
    {
        self::set(self::KEY_SLACK, $value);
    }

    /**
     * Drop the in-memory cache. Call after updating values inside a request,
     * or in test setUp/tearDown.
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function get(string $key): bool
    {
        if (self::$cache === null) {
            self::$cache = DB::table('app_settings')
                ->pluck('value', 'key')
                ->map(fn ($v) => $v === '1')
                ->all();
        }

        return self::$cache[$key] ?? false;
    }

    private static function set(string $key, bool $value): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value ? '1' : '0', 'updated_at' => now()],
        );

        self::flushCache();
    }
}
