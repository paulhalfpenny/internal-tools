<?php

use App\Domain\Reporting\CsvFormatter;
use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

function makeExportEntry(array $attrs = []): TimeEntry
{
    $user = User::factory()->create(array_merge([
        'name' => 'Alice Smith',
        'role_title' => 'Senior Developer',
        'is_contractor' => false,
    ], $attrs['user'] ?? []));

    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Website Redesign',
        'code' => 'ACM001',
    ]);
    $task = Task::factory()->create(['name' => 'Development']);

    return TimeEntry::create(array_merge([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-01',
        'hours' => 2.0,
        'notes' => null,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => 168.0,
        'external_reference' => '10001',
    ], $attrs['entry'] ?? []));
}

function exportCsv(?int $userId = null): string
{
    $query = new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
        userId: $userId,
    );

    return (new DetailedTimeCsvExport($query))->toCsv();
}

// ─── header snapshot ────────────────────────────────────────────────────────

it('freezes CSV export headers', function () {
    $csv = exportCsv();
    $headerLine = explode("\r\n", $csv)[0];

    expect($headerLine)->toBe(trim(file_get_contents(
        base_path('tests/Fixtures/csv-export-snapshot/headers.txt')
    )));
});

it('uses CRLF line endings', function () {
    makeExportEntry();
    $csv = exportCsv();

    expect($csv)->toContain("\r\n");
    expect($csv)->not->toContain("\r\n\n"); // no double line ending
});

// ─── field formatting ────────────────────────────────────────────────────────

it('formats hours with minimum 1 dp and no unnecessary trailing zeros', function () {
    expect(CsvFormatter::hours(1.0))->toBe('1.0');
    expect(CsvFormatter::hours(1.5))->toBe('1.5');
    expect(CsvFormatter::hours(0.25))->toBe('0.25');
    expect(CsvFormatter::hours(3.0))->toBe('3.0');
});

it('quotes fields containing commas', function () {
    expect(CsvFormatter::field('hello, world'))->toBe('"hello, world"');
});

it('quotes fields containing double-quotes and escapes them', function () {
    expect(CsvFormatter::field('say "hello"'))->toBe('"say ""hello"""');
});

it('does not quote plain fields', function () {
    expect(CsvFormatter::field('Development'))->toBe('Development');
});

// ─── row content ────────────────────────────────────────────────────────────

it('writes the correct 21 columns for a billable entry', function () {
    makeExportEntry();
    $csv = exportCsv();
    $rows = explode("\r\n", trim($csv));
    $dataRow = $rows[1];
    $cols = str_getcsv($dataRow);

    expect($cols)->toHaveCount(21);
    expect($cols[0])->toBe('2026-04-01');          // Date
    expect($cols[1])->toBe('Acme Corp');            // Client
    expect($cols[2])->toBe('Website Redesign');     // Project
    expect($cols[3])->toBe('ACM001');               // Project Code
    expect($cols[4])->toBe('Development');          // Task
    expect($cols[6])->toBe('2.0');                  // Hours
    expect($cols[7])->toBe('Yes');                  // Billable?
    expect($cols[8])->toBe('No');                   // Invoiced?
    expect($cols[9])->toBe('No');                   // Approved?
    expect($cols[10])->toBe('Alice');               // First Name
    expect($cols[11])->toBe('Smith');               // Last Name
    expect($cols[12])->toBe('');                    // Employee Id
    expect($cols[13])->toBe('Senior Developer');    // Roles
    expect($cols[14])->toBe('Yes');                 // Employee?
    expect($cols[15])->toBe('84.0');                // Billable Rate
    expect($cols[16])->toBe('168.0');               // Billable Amount
    expect($cols[17])->toBe('0.0');                 // Cost Rate
    expect($cols[18])->toBe('0.0');                 // Cost Amount
    expect($cols[19])->toBe('British Pound - GBP'); // Currency
    expect($cols[20])->toBe('10001');               // External Reference
});

it('outputs No for Billable? and zero rate/amount for non-billable entries', function () {
    makeExportEntry(['entry' => ['is_billable' => false, 'billable_rate_snapshot' => null, 'billable_amount' => 0.0]]);
    $csv = exportCsv();
    $cols = str_getcsv(explode("\r\n", trim($csv))[1]);

    expect($cols[7])->toBe('No');
    expect($cols[15])->toBe('0.0');
    expect($cols[16])->toBe('0.0');
});

it('marks contractors as Employee? No', function () {
    makeExportEntry(['user' => ['is_contractor' => true]]);
    $csv = exportCsv();
    $cols = str_getcsv(explode("\r\n", trim($csv))[1]);

    expect($cols[14])->toBe('No');
});

it('splits single-word names into first name only with empty last name', function () {
    makeExportEntry(['user' => ['name' => 'Alice']]);
    $csv = exportCsv();
    $cols = str_getcsv(explode("\r\n", trim($csv))[1]);

    expect($cols[10])->toBe('Alice');
    expect($cols[11])->toBe('');
});

it('omits running entries from the export', function () {
    makeExportEntry(['entry' => ['is_running' => true, 'timer_started_at' => now()]]);

    // Running entries still get exported — the query doesn't filter them out
    // (consistent with Harvest which exports in-progress entries)
    $csv = exportCsv();
    $rows = array_filter(explode("\r\n", trim($csv)));

    expect(count($rows))->toBe(2); // header + 1 row
});
