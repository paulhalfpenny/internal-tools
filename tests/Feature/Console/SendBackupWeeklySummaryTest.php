<?php

use App\Mail\BackupWeeklySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-08-17 09:00:00');

    config([
        'app.name' => 'Internal Tools',
        'backup.backup.name' => 'internal-tools',
        'backup.backup.destination.disks' => ['do_spaces'],
        'backup.notifications.mail.to' => 'backups@example.test',
    ]);

    Storage::fake('do_spaces');
    Mail::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('sends a summary for backups created in the previous calendar week', function () {
    Storage::disk('do_spaces')->put('internal-tools/2026-08-16-02-00-00.zip', str_repeat('a', 1024));
    Storage::disk('do_spaces')->put('internal-tools/2026-08-10-02-00-00.zip', str_repeat('b', 2048));
    Storage::disk('do_spaces')->put('internal-tools/2026-08-09-02-00-00.zip', str_repeat('c', 4096));

    $this->artisan('backup:send-weekly-summary')
        ->expectsOutputToContain('Weekly backup summary sent.')
        ->assertSuccessful();

    Mail::assertSent(BackupWeeklySummary::class, function (BackupWeeklySummary $mail): bool {
        return $mail->hasTo('backups@example.test')
            && $mail->backupCount === 2
            && $mail->totalSizeInBytes === 3072
            && $mail->newestBackupAt->toDateTimeString() === '2026-08-16 02:00:00'
            && $mail->diskName === 'do_spaces';
    });
});

test('fails without sending an email when the previous calendar week has no backups', function () {
    Storage::disk('do_spaces')->put('internal-tools/2026-08-09-02-00-00.zip', str_repeat('a', 1024));

    $this->artisan('backup:send-weekly-summary')
        ->expectsOutputToContain('No backups were created')
        ->assertFailed();

    Mail::assertNothingSent();
});

test('renders the backup count, storage use, and destination in the summary email', function () {
    $html = (new BackupWeeklySummary(
        backupCount: 2,
        totalSizeInBytes: 3072,
        newestBackupAt: Carbon::parse('2026-08-16 02:00:00'),
        diskName: 'do_spaces',
        periodStart: Carbon::parse('2026-08-10'),
        periodEnd: Carbon::parse('2026-08-16'),
    ))->render();

    expect($html)->toContain('Backups created: 2')
        ->and($html)->toContain('Total size: 3.00 KB')
        ->and($html)->toContain('Destination: do_spaces');
});

test('sends immediate email only for backup failures and unhealthy backups', function () {
    expect(config('backup.notifications.notifications'))->toBe([
        BackupHasFailedNotification::class => ['mail'],
        UnhealthyBackupWasFoundNotification::class => ['mail'],
        CleanupHasFailedNotification::class => ['mail'],
    ]);
});

test('schedules the backup summary every Monday at 09:00', function () {
    Artisan::call('schedule:list');

    $schedule = Artisan::output();

    expect($schedule)->toContain('php artisan backup:send-weekly-summary')
        ->and(preg_match('/0\\s+9\\s+\\*\\s+\\*\\s+1\\s+php artisan backup:send-weekly-summary/', $schedule))->toBe(1);
});
