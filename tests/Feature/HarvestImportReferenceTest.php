<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

$fixture = __DIR__.'/../Fixtures/harvest-csv/detailed-time-fixture.csv';

it('--since skips entries dated before the cutoff', function () use ($fixture) {
    // Fixture has rows on 2026-04-01 and 2026-04-02. With --since=2026-04-02 only the
    // 2026-04-02 rows should drive creation: Zeta Ltd / Support / Customer Support and
    // Acme Corp / Website Redesign / Development.
    $this->artisan('harvest:import-reference', ['path' => $fixture, '--since' => '2026-04-02'])
        ->assertSuccessful();

    expect(Client::pluck('name')->all())
        ->toEqualCanonicalizing(['Acme Corp', 'Zeta Ltd']);
    expect(Project::pluck('name')->all())
        ->toEqualCanonicalizing(['Support', 'Website Redesign']);

    // 2026-04-01 introduced "Testing"; it must not be present after the cutoff filter.
    expect(Task::pluck('name')->all())
        ->toEqualCanonicalizing(['Customer Support', 'Development']);

    expect(DB::table('project_task')->count())->toBe(2);
});
