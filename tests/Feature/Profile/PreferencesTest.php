<?php

use App\Domain\TimeTracking\HoursFormatter;
use App\Livewire\Profile\Preferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('switching preference does not clobber other schedule preferences', function () {
    $user = User::factory()->create(['schedule_preferences' => ['some_other_key' => 'keep-me']]);
    $this->actingAs($user);

    Livewire::test(Preferences::class)->call('setFormat', HoursFormatter::FORMAT_HHMM);

    expect($user->fresh()->schedule_preferences)->toBe([
        'some_other_key' => 'keep-me',
        'hours_display_format' => HoursFormatter::FORMAT_HHMM,
    ]);
});
