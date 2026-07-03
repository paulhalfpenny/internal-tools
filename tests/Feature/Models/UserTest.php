<?php

use App\Domain\TimeTracking\HoursFormatter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('hoursDisplayFormat defaults to decimal when no preference is set', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_DECIMAL);
});

test('hoursDisplayFormat returns hhmm when set in schedule_preferences', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'hhmm']]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_HHMM);
});

test('hoursDisplayFormat falls back to decimal for an unrecognised stored value', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'nonsense']]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_DECIMAL);
});
