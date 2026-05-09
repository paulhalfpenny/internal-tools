<?php

namespace App\Providers;

use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Channels\SlackChannel;
use App\Observers\TimeEntryAsanaObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('google-oauth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('asana-oauth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        Gate::define('access-admin', fn (User $user) => $user->isAdmin());
        Gate::define('access-reports', fn (User $user) => $user->isManager());

        // Allowed to look (read-only) at another user's timesheet:
        //   - admins (can also impersonate via the admin route which writes)
        //   - the target's direct line manager
        Gate::define('view-team-timesheet', fn (User $user, User $target) => $user->isAdmin() || $target->reports_to_user_id === $user->id);

        TimeEntry::observe(TimeEntryAsanaObserver::class);

        Notification::extend('slack', fn ($app) => $app->make(SlackChannel::class));
    }
}
