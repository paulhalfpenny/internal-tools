# Backup Notification Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send a concise email summary of the previous week's backups every Monday while retaining immediate backup-failure alerts.

**Architecture:** A dedicated Artisan command reads the configured Spatie backup destination, filters its backups to the preceding seven calendar days, and sends a raw operational summary through Laravel's mailer. The schedule invokes that command weekly; Spatie's event-to-channel map is reduced to failure and unhealthy-health notifications only.

**Tech Stack:** Laravel 11, Pest 3, Spatie Laravel Backup 9, Laravel Mail, Laravel Scheduler.

**Spec:** `docs/superpowers/specs/2026-08-17-backup-notification-summary-design.md`

## Global Constraints

- Preserve the nightly backup at `02:00` and cleanup at `02:30`.
- Schedule the summary every Monday at `09:00` in `APP_TIMEZONE`.
- Send immediate email only for `BackupHasFailedNotification`, `CleanupHasFailedNotification`, and `UnhealthyBackupWasFoundNotification`.
- Fail loudly when the destination is unavailable or contains no backup from the summary period.
- Do not commit, push, or deploy.

---

### Task 1: Weekly Backup Summary Command

**Files:**
- Create: `app/Console/Commands/SendBackupWeeklySummary.php`
- Create: `tests/Feature/Console/SendBackupWeeklySummaryTest.php`

**Interfaces:**
- Consumes: `Spatie\Backup\BackupDestination\BackupDestination::create(string $diskName, string $backupName)` and `config('backup.backup.name')`.
- Produces: Artisan command `backup:send-weekly-summary` that sends a plaintext email to `config('backup.notifications.mail.to')`.

- [ ] **Step 1: Write the failing tests**

```php
test('sends a summary for backups from the previous seven calendar days', function () {
    Carbon::setTestNow('2026-08-17 09:00:00');
    Storage::fake('do_spaces');
    config(['backup.backup.name' => 'internal-tools']);
    Storage::disk('do_spaces')->put('internal-tools/2026-08-16-02-00-00.zip', 'recent');
    Storage::disk('do_spaces')->put('internal-tools/2026-08-09-02-00-00.zip', 'expired');
    Mail::fake();

    $this->artisan('backup:send-weekly-summary')->assertSuccessful();

    Mail::assertSent(function (RawMessage $message): bool {
        return $message->hasTo('backups@example.test')
            && $message->subject === '[Internal Tools] Weekly backup summary'
            && str_contains($message->getHtmlBody(), 'Backups created: 1');
    });
});
```

```php
test('fails instead of sending a summary when no recent backup exists', function () {
    Carbon::setTestNow('2026-08-17 09:00:00');
    Storage::fake('do_spaces');
    config(['backup.backup.name' => 'internal-tools']);
    Mail::fake();

    $this->artisan('backup:send-weekly-summary')->assertFailed();

    Mail::assertNothingSent();
});
```

- [ ] **Step 2: Run the targeted test to verify it fails**

Run: `php artisan test tests/Feature/Console/SendBackupWeeklySummaryTest.php`

Expected: FAIL because `backup:send-weekly-summary` is not defined.

- [ ] **Step 3: Implement the minimal command**

```php
$destination = BackupDestination::create($diskName, config('backup.backup.name'));

if (! $destination->isReachable()) {
    $this->error("Backup destination [{$diskName}] is not reachable.");

    return self::FAILURE;
}

$periodStart = now()->subDays(7)->startOfDay();
$backups = $destination->backups()->filter(
    fn (Backup $backup): bool => $backup->date()->greaterThanOrEqualTo($periodStart)
);
```

Send the count, total human-readable size, newest timestamp, and disk name through `Mail::raw`. Return `self::FAILURE` before sending if no matching backup is found.

- [ ] **Step 4: Run the targeted test to verify it passes**

Run: `php artisan test tests/Feature/Console/SendBackupWeeklySummaryTest.php`

Expected: PASS with two tests.

### Task 2: Notification Routing and Schedule

**Files:**
- Modify: `config/backup.php:3-164`
- Modify: `bootstrap/app.php:34-38`
- Modify: `tests/Feature/Console/SendBackupWeeklySummaryTest.php`

**Interfaces:**
- Consumes: `backup:send-weekly-summary` from Task 1.
- Produces: Monday 09:00 schedule entry and a Spatie notification map limited to immediate alert classes.

- [ ] **Step 1: Write the failing configuration and schedule assertions**

```php
test('keeps only failure backup notifications on email', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications)->toBe([
        BackupHasFailedNotification::class => ['mail'],
        UnhealthyBackupWasFoundNotification::class => ['mail'],
        CleanupHasFailedNotification::class => ['mail'],
    ]);
});

test('schedules the backup summary every Monday at 09:00', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'backup:send-weekly-summary'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * 1');
});
```

- [ ] **Step 2: Run the targeted test to verify it fails**

Run: `php artisan test tests/Feature/Console/SendBackupWeeklySummaryTest.php`

Expected: FAIL because successful notifications remain configured and no weekly schedule entry exists.

- [ ] **Step 3: Implement the minimal routing and schedule changes**

```php
'notifications' => [
    BackupHasFailedNotification::class => ['mail'],
    UnhealthyBackupWasFoundNotification::class => ['mail'],
    CleanupHasFailedNotification::class => ['mail'],
],
```

```php
$schedule->command('backup:send-weekly-summary')->mondays()->at('09:00');
```

- [ ] **Step 4: Run the targeted test to verify it passes**

Run: `php artisan test tests/Feature/Console/SendBackupWeeklySummaryTest.php`

Expected: PASS with four tests.

- [ ] **Step 5: Run focused formatting and verification**

Run: `vendor/bin/pint --dirty && php artisan test tests/Feature/Console/SendBackupWeeklySummaryTest.php && php artisan schedule:list`

Expected: formatter exits 0, all four tests pass, and the command is listed at `0 9 * * 1`.
