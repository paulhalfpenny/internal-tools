<?php

use App\Livewire\Reports\TeamOverviewReport;
use App\Livewire\Reports\TeamReport;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('team member drill down retains the overview report period', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['name' => 'April project']);

    TimeEntry::factory()->create([
        'user_id' => $member->id,
        'project_id' => $project->id,
        'spent_on' => '2026-04-15',
    ]);

    $this->actingAs($admin);

    $period = [
        'preset' => 'custom',
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ];

    $overview = Livewire::test(TeamOverviewReport::class)
        ->set('preset', $period['preset'])
        ->set('from', $period['from'])
        ->set('to', $period['to']);

    $memberUrl = route('reports.team.member', [
        'user' => $member->id,
        ...$period,
    ]);

    expect(html_entity_decode($overview->html(), ENT_QUOTES | ENT_HTML5))
        ->toContain('href="'.$memberUrl.'"');

    Livewire::withQueryParams($period)
        ->test(TeamReport::class, ['user' => $member])
        ->assertSet('preset', $period['preset'])
        ->assertSet('from', $period['from'])
        ->assertSet('to', $period['to'])
        ->assertSee($project->name);
});
