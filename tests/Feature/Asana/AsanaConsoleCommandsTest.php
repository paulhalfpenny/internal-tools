<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('asana task refresh is scheduled every fifteen minutes', function () {
    Artisan::call('schedule:list');

    $schedule = Artisan::output();

    expect($schedule)->toContain('php artisan asana:refresh-tasks');
    expect(preg_match('/\*\/15\s+\*\s+\*\s+\*\s+\*\s+php artisan asana:refresh-tasks/', $schedule))->toBe(1);
});
