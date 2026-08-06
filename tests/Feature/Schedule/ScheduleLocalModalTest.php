<?php

use App\Livewire\Schedule\ScheduleBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('schedule modal visibility is local while assignment validation remains server-side', function () {
    $admin = User::factory()->admin()->create();

    $html = $this->actingAs($admin)->get(route('schedule'))->assertOk()->getContent();

    foreach (['openAssignment', 'openTimeOff', 'openPlaceholder', 'openShift', 'closeModal'] as $handler) {
        expect($html)->toContain("{$handler}(");
    }

    expect($html)
        ->not->toContain('wire:click="openAssignmentModal')
        ->not->toContain('wire:click="editAssignment')
        ->not->toContain('wire:click="openTimeOffModal')
        ->not->toContain('wire:click="editTimeOff')
        ->not->toContain('wire:click="openPlaceholderModal')
        ->not->toContain('wire:click="editPlaceholder')
        ->not->toContain('wire:click="openShiftTimeline')
        ->not->toContain('wire:click="closeAssignmentModal')
        ->not->toContain('wire:click="closeTimeOffModal')
        ->not->toContain('wire:click="closePlaceholderModal')
        ->not->toContain('wire:click="closeShiftModal');

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->call('saveAssignment')
        ->assertHasErrors(['assignmentProjectId', 'assignmentStartsOn', 'assignmentEndsOn']);
});
