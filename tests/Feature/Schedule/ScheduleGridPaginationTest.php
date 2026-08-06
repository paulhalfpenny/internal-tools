<?php

use App\Livewire\Schedule\ScheduleBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('schedule limits the team grid to one 25-row page', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(25)->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->assertViewHas('teamRows', fn (array $rows) => count($rows) === 25)
        ->assertViewHas('gridPage', 1)
        ->assertViewHas('gridLastPage', 2)
        ->call('nextGridPage')
        ->assertSet('gridPage', 2)
        ->assertViewHas('teamRows', fn (array $rows) => count($rows) === 1);
});

test('changing the schedule period returns the grid to the first page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('gridPage', 2)
        ->call('nextPeriod')
        ->assertSet('gridPage', 1);
});
