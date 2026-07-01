<?php

use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project exposes stable Asana task matching terms for shared boards', function () {
    $client = Client::factory()->create(['name' => 'Tomorrows Guides']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'code' => 'TOG013',
        'name' => 'CRO Improvements - carehome.co.uk - Build Phase',
    ]);

    expect($project->asanaTaskMatchTerms())
        ->toContain('tog013')
        ->toContain('tomorrows guides')
        ->toContain('tomorrowsguides')
        ->toContain('carehome.co.uk')
        ->toContain('carehome')
        ->toContain('cro improvements')
        ->not->toContain('build')
        ->not->toContain('phase');
});
