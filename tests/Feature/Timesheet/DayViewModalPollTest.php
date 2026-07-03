<?php

use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The 60s timer poll re-renders and morphs the whole component. A morph
// landing while the entry modal's pickers are open leaves orphaned Alpine
// template clones (ghost dropdowns / cleared-looking fields), so polling
// must pause while the modal is open.
test('day view timer poll is suspended while the entry modal is open', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->assertSee('wire:poll', false)
        ->call('openNewModal')
        ->assertDontSee('wire:poll', false)
        ->call('closeModal')
        ->assertSee('wire:poll', false);
});
