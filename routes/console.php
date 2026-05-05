<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('asana:refresh-projects')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('asana:refresh-tasks')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('asana:prune-logs')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();
