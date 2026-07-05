<?php

use App\Domain\TimeTracking\HoursFormatter;
use App\Livewire\Profile\Preferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('mounts with the users current hours display format', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'hhmm']]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->assertSet('hoursFormat', HoursFormatter::FORMAT_HHMM);
});

test('defaults to decimal when no preference has been saved', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->assertSet('hoursFormat', HoursFormatter::FORMAT_DECIMAL);
});

test('user can switch their hours display format to hhmm', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->call('setFormat', HoursFormatter::FORMAT_HHMM)
        ->assertSet('hoursFormat', HoursFormatter::FORMAT_HHMM);

    expect($user->fresh()->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_HHMM);
});

test('user can switch back to decimal', function () {
    $user = User::factory()->create(['schedule_preferences' => ['hours_display_format' => 'hhmm']]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->call('setFormat', HoursFormatter::FORMAT_DECIMAL)
        ->assertSet('hoursFormat', HoursFormatter::FORMAT_DECIMAL);

    expect($user->fresh()->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_DECIMAL);
});

test('setting an invalid format is ignored', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->call('setFormat', 'nonsense')
        ->assertSet('hoursFormat', HoursFormatter::FORMAT_DECIMAL);

    expect($user->fresh()->hoursDisplayFormat())->toBe(HoursFormatter::FORMAT_DECIMAL);
});

test('saving a valid format dispatches a saved event', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->call('setFormat', HoursFormatter::FORMAT_HHMM)
        ->assertDispatched('preference-saved');
});

test('an ignored invalid format does not dispatch a saved event', function () {
    $user = User::factory()->create(['schedule_preferences' => null]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)
        ->call('setFormat', 'nonsense')
        ->assertNotDispatched('preference-saved');
});

test('switching preference does not clobber other schedule preferences', function () {
    $user = User::factory()->create(['schedule_preferences' => ['some_other_key' => 'keep-me']]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)->call('setFormat', HoursFormatter::FORMAT_HHMM);

    expect($user->fresh()->schedule_preferences)->toBe([
        'some_other_key' => 'keep-me',
        'hours_display_format' => HoursFormatter::FORMAT_HHMM,
    ]);
});
