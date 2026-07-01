<?php

use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function taskPickerDropdownSetup(): User
{
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $project = Project::factory()->create(['name' => 'Internal']);
    $task = Task::factory()->create(['name' => 'Holiday', 'is_default_billable' => false]);

    $project->tasks()->attach($task->id, ['is_billable' => false, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return $user;
}

function taskPickerMethodBody(string $html): string
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    preg_match('/pickTask\(id\) \{(?P<body>.*?)\n\s*\},\n\s*pickAsanaTask/s', $html, $matches);

    expect($matches)->not->toBeEmpty();

    return $matches['body'];
}

function assertTaskPickerClosesAfterLivewireSync(string $html, string $wireSyncStatement): void
{
    $methodBody = taskPickerMethodBody($html);

    expect($methodBody)->toContain('this.closePickers();');

    $closePosition = strpos($methodBody, 'this.closePickers();');
    $wirePosition = strpos($methodBody, $wireSyncStatement);

    expect($wirePosition)->not->toBeFalse();
    expect($closePosition)->toBeGreaterThan($wirePosition);
}

function assertTaskPickerHasSearch(string $html): void
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    expect($html)
        ->toContain("taskSearch: ''")
        ->toContain('x-model="taskSearch"')
        ->toContain('placeholder="Search tasks…"')
        ->toContain('filteredBillableTasks')
        ->toContain('filteredNonBillableTasks')
        ->toContain('No tasks match.');
}

test('day view closes the task dropdown after syncing the selected task', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->html();

    assertTaskPickerClosesAfterLivewireSync($html, '$wire.selectedTaskId = id;');
});

test('day view task dropdown can be searched by typing', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->html();

    assertTaskPickerHasSearch($html);
});

test('week view closes the task dropdown after syncing the selected task', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->html();

    assertTaskPickerClosesAfterLivewireSync($html, "\$wire.set('newRowTaskId', id);");
});

test('week view task dropdown can be searched by typing', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->html();

    assertTaskPickerHasSearch($html);
});
