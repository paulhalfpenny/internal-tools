<?php

use App\Console\Commands\HistoricalHarvestTimeImportManifest;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest(historicalHarvestTestManifest())
    );
});

const HISTORICAL_HARVEST_HEADERS = [
    'Date',
    'Client',
    'Project',
    'Project Code',
    'Task',
    'Notes',
    'Hours',
    'Billable?',
    'Invoiced?',
    'Approved?',
    'First Name',
    'Last Name',
    'Employee Id',
    'Roles',
    'Teams',
    'Employee?',
    'Billable Rate',
    'Billable Amount',
    'Cost Rate',
    'Cost Amount',
    'Currency',
    'External Reference URL',
];

/**
 * @return list<array{
 *     target_code: string,
 *     source_client: string,
 *     source_code: string,
 *     source_project: string,
 *     from: string|null,
 *     expected_rows: int,
 *     table_amount: int
 * }>
 */
function historicalHarvestTestManifest(): array
{
    return [
        ['target_code' => 'DEN004', 'source_client' => '123Dentist', 'source_code' => 'DEN004', 'source_project' => 'Continuous Improvements Retainer (September 2025 - August 2026)', 'from' => '2025-09-01', 'expected_rows' => 3, 'table_amount' => 300],
        ['target_code' => 'EAA001', 'source_client' => 'East Anglian Air Ambulance (EAAA)', 'source_code' => 'EAA001', 'source_project' => 'Continuous Improvements Retainer (August 2025 - July 2026)', 'from' => '2026-02-01', 'expected_rows' => 1, 'table_amount' => 100],
        ['target_code' => 'FUN006', 'source_client' => 'Fundraising Everywhere', 'source_code' => 'FUN008', 'source_project' => 'Continuous Improvements Retainer Uplift 2026', 'from' => '2026-06-01', 'expected_rows' => 2, 'table_amount' => 200],
        ['target_code' => 'HOP005', 'source_client' => 'Homeprotect', 'source_code' => 'HOP005', 'source_project' => 'WebMCP Project', 'from' => null, 'expected_rows' => 1, 'table_amount' => 100],
    ];
}

function createHistoricalHarvestReferences(): void
{
    foreach (historicalHarvestTestManifest() as $definition) {
        $client = Client::factory()->create(['name' => 'Target '.$definition['target_code']]);
        Project::factory()->create([
            'client_id' => $client->id,
            'code' => $definition['target_code'],
            'name' => 'Target '.$definition['target_code'],
        ]);
    }

    User::factory()->create(['name' => 'Import User']);
    User::factory()->create(['name' => 'Dave Page']);
    Task::factory()->create(['name' => 'Development']);
    Task::factory()->create(['name' => 'Design & UX']);
}

/** @return list<array<string, string>> */
function reconciledHistoricalHarvestRows(): array
{
    $rows = [];

    foreach (historicalHarvestTestManifest() as $definition) {
        $tableAmountPence = $definition['table_amount'] * 100;
        $basePence = intdiv($tableAmountPence, $definition['expected_rows']);
        $remainder = $tableAmountPence % $definition['expected_rows'];

        for ($index = 0; $index < $definition['expected_rows']; $index++) {
            $amountPence = $basePence + ($index < $remainder ? 1 : 0);
            $amount = $amountPence / 100;

            $rows[] = [
                'Date' => $definition['from'] ?? '2026-06-09',
                'Client' => $definition['source_client'],
                'Project' => $definition['source_project'],
                'Project Code' => $definition['source_code'],
                'Task' => 'Development',
                'Notes' => $definition['target_code'].' row '.$index,
                'Hours' => number_format($amount / 100, 2, '.', ''),
                'Billable?' => 'Yes',
                'Invoiced?' => 'No',
                'Approved?' => 'No',
                'First Name' => 'Import',
                'Last Name' => 'User',
                'Employee Id' => '',
                'Roles' => 'Developer',
                'Teams' => 'Delivery',
                'Employee?' => 'Yes',
                'Billable Rate' => '100.00',
                'Billable Amount' => number_format($amount, 2, '.', ''),
                'Cost Rate' => '0.00',
                'Cost Amount' => '0.00',
                'Currency' => 'British Pound - GBP',
                'External Reference URL' => '',
            ];
        }
    }

    return $rows;
}

/** @param list<array<string, string>> $rows */
function writeHistoricalHarvestCsv(string $path, array $rows): string
{
    $handle = fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException("Unable to create {$path}");
    }

    fputcsv($handle, HISTORICAL_HARVEST_HEADERS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv(
            $handle,
            array_map(fn (string $header): string => $row[$header], HISTORICAL_HARVEST_HEADERS),
            ',',
            '"',
            ''
        );
    }
    fclose($handle);

    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException("Unable to hash {$path}");
    }

    return $hash;
}

test('historical Harvest import commits mapped fields and only adds time entries and ledger rows', function () {
    Queue::fake();
    $manifest = historicalHarvestTestManifest();
    $manifest[0]['table_amount'] = 1300;
    app()->instance(
        HistoricalHarvestTimeImportManifest::class,
        new HistoricalHarvestTimeImportManifest($manifest)
    );
    createHistoricalHarvestReferences();
    $rows = reconciledHistoricalHarvestRows();

    $rows[0]['Hours'] = '11.02';
    $rows[0]['Billable Amount'] = '1,102.00';
    $rows[1]['Hours'] = '0.99';
    $rows[1]['Billable Amount'] = '99.00';
    $rows[2]['Hours'] = '0.99';
    $rows[2]['Billable Amount'] = '99.00';
    $firstAmount = 1102.0;
    $rows[0]['First Name'] = 'David';
    $rows[0]['Last Name'] = 'Page';
    $rows[0]['Task'] = 'Design';
    $rows[0]['Notes'] = 'Imported note, with punctuation';
    $rows[0]['External Reference URL'] = 'https://app.asana.com/0/123/456';
    $firstHours = (float) $rows[0]['Hours'];

    $nonBillableAmount = (float) $rows[1]['Billable Amount'];
    $rows[1]['Billable?'] = 'No';
    $rows[1]['Billable Rate'] = '0.00';
    $rows[1]['Billable Amount'] = '0.00';
    $rows[2]['Hours'] = '1.98';
    $rows[2]['Billable Amount'] = number_format((float) $rows[2]['Billable Amount'] + $nonBillableAmount, 2, '.', '');

    $excluded = $rows[0];
    $excluded['Project'] = 'Postcode Risk Assessment Calculator';
    $excluded['Project Code'] = 'HOP005';
    $excluded['Client'] = 'Homeprotect';
    $rows[] = $excluded;

    $beforeStart = $rows[3];
    $beforeStart['Date'] = '2026-01-31';
    $beforeStart['Notes'] = 'Excluded before start';
    $rows[] = $beforeStart;

    $afterCutoff = $rows[0];
    $afterCutoff['Date'] = '2026-07-01';
    $afterCutoff['Notes'] = 'Excluded after cutoff';
    $rows[] = $afterCutoff;

    $path = storage_path('framework/testing/historical-harvest-commit.csv');
    $hash = writeHistoricalHarvestCsv($path, $rows);
    unset($rows);
    $existingProject = Project::query()->where('code', 'HOP005')->firstOrFail();
    $existing = TimeEntry::factory()->create([
        'project_id' => $existingProject->id,
        'user_id' => User::query()->where('name', 'Import User')->firstOrFail()->id,
        'task_id' => Task::query()->where('name', 'Development')->firstOrFail()->id,
        'spent_on' => '2025-01-01',
        'hours' => 1.0,
        'notes' => 'Unrelated existing entry',
    ]);
    $existingBefore = (array) DB::table('time_entries')->where('id', $existing->id)->first();
    $beforeProjects = Project::query()->orderBy('id')->get()->map->getAttributes()->all();
    $beforeCounts = [
        'clients' => Client::count(),
        'projects' => Project::count(),
        'users' => User::count(),
        'tasks' => Task::count(),
        'project_tasks' => DB::table('project_task')->count(),
    ];

    $this->artisan('app:one-off-historical-harvest-time', [
        'path' => $path,
        '--expected-sha256' => $hash,
        '--commit' => true,
    ])->assertSuccessful();

    expect(TimeEntry::count())->toBe(8)
        ->and(DB::table('harvest_import_log')->count())->toBe(7)
        ->and(Client::count())->toBe($beforeCounts['clients'])
        ->and(Project::count())->toBe($beforeCounts['projects'])
        ->and(User::count())->toBe($beforeCounts['users'])
        ->and(Task::count())->toBe($beforeCounts['tasks'])
        ->and(DB::table('project_task')->count())->toBe($beforeCounts['project_tasks'])
        ->and(Project::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeProjects)
        ->and((array) DB::table('time_entries')->where('id', $existing->id)->first())->toBe($existingBefore)
        ->and(TimeEntry::query()->whereIn('notes', ['Excluded before start', 'Excluded after cutoff'])->count())->toBe(0);

    $imported = TimeEntry::query()->where('notes', 'Imported note, with punctuation')->firstOrFail();
    expect($imported->project->code)->toBe('DEN004')
        ->and($imported->user->name)->toBe('Dave Page')
        ->and($imported->task->name)->toBe('Design & UX')
        ->and($imported->spent_on->toDateString())->toBe('2025-09-01')
        ->and((float) $imported->hours)->toBe($firstHours)
        ->and($imported->notes)->toBe('Imported note, with punctuation')
        ->and($imported->is_running)->toBeFalse()
        ->and($imported->timer_started_at)->toBeNull()
        ->and($imported->is_billable)->toBeTrue()
        ->and((float) $imported->billable_rate_snapshot)->toBe(100.0)
        ->and((float) $imported->billable_amount)->toBe($firstAmount)
        ->and($imported->invoiced_at)->toBeNull()
        ->and($imported->external_reference)->toBe('https://app.asana.com/0/123/456')
        ->and($imported->asana_task_gid)->toBeNull()
        ->and($imported->asana_synced_at)->toBeNull()
        ->and($imported->asana_sync_error)->toBeNull();

    $nonBillable = TimeEntry::query()->where('notes', 'DEN004 row 1')->firstOrFail();
    expect($nonBillable->is_billable)->toBeFalse()
        ->and($nonBillable->billable_rate_snapshot)->toBeNull()
        ->and((float) $nonBillable->billable_amount)->toBe(0.0)
        ->and(TimeEntry::query()->whereHas('project', fn ($query) => $query->where('code', 'FUN006'))->count())->toBe(2)
        ->and(TimeEntry::query()->whereHas('project', fn ($query) => $query->where('code', 'EAA001'))->count())->toBe(1);

    Queue::assertNotPushed(SyncAsanaTaskHoursJob::class);
});

test('historical Harvest import is idempotent and preserves identical source events', function () {
    createHistoricalHarvestReferences();
    $rows = reconciledHistoricalHarvestRows();
    $rows[1] = $rows[0];

    $path = storage_path('framework/testing/historical-harvest-idempotent.csv');
    $hash = writeHistoricalHarvestCsv($path, $rows);
    unset($rows);
    $arguments = ['path' => $path, '--expected-sha256' => $hash, '--commit' => true];

    $this->artisan('app:one-off-historical-harvest-time', $arguments)->assertSuccessful();
    $this->artisan('app:one-off-historical-harvest-time', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Already imported: 7');

    $changedRows = reconciledHistoricalHarvestRows();
    $changedRows[1] = $changedRows[0];
    $changedRows[0]['Date'] = '2025-09-02';
    $changedPath = storage_path('framework/testing/historical-harvest-changed-source.csv');
    $changedHash = writeHistoricalHarvestCsv($changedPath, $changedRows);
    unset($changedRows);

    $this->artisan('app:one-off-historical-harvest-time', [
        'path' => $changedPath,
        '--expected-sha256' => $changedHash,
        '--commit' => true,
    ])->assertFailed()->expectsOutputToContain('belongs to source SHA-256');

    expect(TimeEntry::count())->toBe(7)
        ->and(DB::table('harvest_import_log')->count())->toBe(7)
        ->and(DB::table('harvest_import_log')->distinct()->count('source_harvest_id'))->toBe(7)
        ->and(TimeEntry::query()->where('notes', 'DEN004 row 0')->count())->toBe(2);
});
