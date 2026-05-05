<?php

namespace App\Providers;

use App\Models\TimeEntry;
use App\Models\User;
use App\Observers\TimeEntryAsanaObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        TimeEntry::observe(TimeEntryAsanaObserver::class);
    }
}
