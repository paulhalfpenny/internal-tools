<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$fixture = __DIR__.'/../Fixtures/harvest-csv/detailed-time-fixture.csv';

beforeEach(function () {
    // The fixture CSV has users Alice Smith and Bob Jones
    User::factory()->create(['name' => 'Alice Smith', 'role_title' => null]);
    User::factory()->create(['name' => 'Bob Jones', 'role_title' => null]);

    Client::factory()->create(['name' => 'Acme Corp']);
    Client::factory()->create(['name' => 'Zeta Ltd']);

    $acme = Client::where('name', 'Acme Corp')->first();
    $zeta = Client::where('name', 'Zeta Ltd')->first();

    Project::factory()->create(['name' => 'Website Redesign', 'client_id' => $acme->id, 'code' => 'ACM001']);
    Project::factory()->create(['name' => 'Support', 'client_id' => $zeta->id, 'code' => 'ZET001']);

    Task::factory()->create(['name' => 'Development']);
    Task::factory()->create(['name' => 'Testing']);
    Task::factory()->create(['name' => 'Customer Support']);
});

it('import is idempotent — re-running does not duplicate rows', function () use ($fixture) {
    $this->artisan('harvest:import', ['path' => $fixture])->assertSuccessful();
    $this->artisan('harvest:import', ['path' => $fixture])->assertSuccessful();

    expect(TimeEntry::count())->toBe(4);
});

it('correctly maps billable fields', function () use ($fixture) {
    $this->artisan('harvest:import', ['path' => $fixture])->assertSuccessful();

    $entry = TimeEntry::where('external_reference', '10001')->first();
    expect($entry->is_billable)->toBeTrue();
    expect((float) $entry->billable_rate_snapshot)->toBe(84.0);
    expect((float) $entry->billable_amount)->toBe(168.0);
});

it('dry-run writes nothing to the database', function () use ($fixture) {
    $this->artisan('harvest:import', ['path' => $fixture, '--dry-run' => true])
        ->assertSuccessful();

    expect(TimeEntry::count())->toBe(0);
});
