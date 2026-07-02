<?php

use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function projectPickerDisplayNameSetup(): User
{
    Cache::flush();

    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $task = Task::factory()->create(['name' => 'Development']);

    $agencyClient = Client::factory()->create(['name' => 'Tomorrows Guides', 'code' => 'TOG']);
    $agencyProject = Project::factory()->create([
        'client_id' => $agencyClient->id,
        'code' => 'TOG013',
        'name' => 'CRO Improvements - carehome.co.uk - Build Phase',
    ]);

    $jdwClient = Client::factory()->create(['name' => 'JDW Projects', 'code' => 'JDW']);
    $jdwProject = Project::factory()->create([
        'client_id' => $jdwClient->id,
        'code' => 'JDW001',
        'name' => 'Customer App / PWA / App Manager',
    ]);

    foreach ([$agencyProject, $jdwProject] as $project) {
        $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
        $project->users()->attach($user->id, ['hourly_rate_override' => null]);
    }

    return $user;
}

function assertProjectPickerUsesCodedAgencyLabels(string $html): void
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    expect($html)
        ->toContain('[TOG013] CRO Improvements - carehome.co.uk - Build Phase')
        ->toContain('return this.selectedProject ? (this.selectedProject.display_name ?? this.selectedProject.name) : \'\';')
        ->toContain(':value="projectOpen ? projectSearch : selectedProjectLabel"')
        ->toContain('x-text="project.display_name ?? project.name"')
        ->not->toContain('[JDW001] Customer App / PWA / App Manager');
}

test('day view project picker shows Harvest-style code prefixes for agency projects', function () {
    $user = projectPickerDisplayNameSetup();
    $this->actingAs($user);

    $html = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->html();

    assertProjectPickerUsesCodedAgencyLabels($html);
});

test('week view project picker shows Harvest-style code prefixes for agency projects', function () {
    $user = projectPickerDisplayNameSetup();
    $this->actingAs($user);

    $html = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->html();

    assertProjectPickerUsesCodedAgencyLabels($html);
});
