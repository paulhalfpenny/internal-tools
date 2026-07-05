<?php

use App\Domain\TimeTracking\HoursFormatter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('hoursDisplayFormat defaults to hhmm when no preference is set', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_HHMM);
});

test('hoursDisplayFormat returns hhmm when set in schedule_preferences', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'hhmm']]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_HHMM);
});

test('hoursDisplayFormat returns decimal only when explicitly set', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'decimal']]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_DECIMAL);
});

test('hoursDisplayFormat falls back to hhmm for an unrecognised stored value', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'nonsense']]);

    expect($user->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_HHMM);
});
