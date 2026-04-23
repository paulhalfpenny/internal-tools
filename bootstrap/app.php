<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Nightly DB backup to DO Spaces at 02:00, then cleanup at 02:30.
        $schedule->command('backup:run --only-db')->dailyAt('02:00');
        $schedule->command('backup:clean')->dailyAt('02:30');

        // Restore-test on the first Saturday of each month (staging only).
        $schedule->command('backup:restore-test')
            ->weeklyOn(6, '04:00')
            ->when(fn () => now()->day <= 7);
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
