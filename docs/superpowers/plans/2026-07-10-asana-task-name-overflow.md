# Asana Task Name Overflow Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve long Asana task names and prevent recurring task-sync failures caused by the 255-character database limit.

**Architecture:** Keep the full task name returned by Asana and widen the cache column from `VARCHAR(255)` to `TEXT`. Prove both the schema and sync behavior with the existing Asana pull-job feature tests, then verify the migration in both directions before release.

**Tech Stack:** Laravel 11, PHP 8.2, Pest 3, SQLite for automated tests, MySQL 8 in production.

## Global Constraints

- Preserve full Asana task names; do not truncate user data.
- Keep the existing `PullAsanaTasksJob` write path unchanged.
- The production deploy runs migrations automatically after merging to `main`.
- Use the existing `RefreshDatabase` Asana feature-test setup.

---

### Task 1: Add the long-name regression test

**Files:**
- Modify: `tests/Feature/Asana/PullJobsTest.php`
- Test: `tests/Feature/Asana/PullJobsTest.php`

**Interfaces:**
- Consumes: `PullAsanaTasksJob::handle(AsanaService $service): void` and the `asana_tasks.name` schema.
- Produces: A regression test requiring a `TEXT` column and exact preservation of a task name longer than 255 characters.

- [ ] **Step 1: Write the failing test**

```php
use Illuminate\Support\Facades\Schema;

test('pulling tasks preserves names longer than 255 characters', function () {
    $user = asanaTestConnectedUser();
    $longName = str_repeat('Long Asana task name ', 16);

    expect(mb_strlen($longName))->toBeGreaterThan(255);
    expect(Schema::getColumnType('asana_tasks', 'name'))->toBe('text');

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response([
            'data' => [
                ['gid' => 't-long', 'name' => $longName, 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]),
    ]);

    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect(AsanaTask::find('t-long')->name)->toBe($longName);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Asana/PullJobsTest.php --filter='preserves names longer than 255 characters'`

Expected: FAIL because `Schema::getColumnType('asana_tasks', 'name')` returns `varchar` instead of `text`.

### Task 2: Widen the cached task-name column

**Files:**
- Create: `database/migrations/2026_07_10_120000_change_asana_task_name_to_text.php`
- Test: `tests/Feature/Asana/PullJobsTest.php`

**Interfaces:**
- Consumes: Existing `asana_tasks.name` values written by `PullAsanaTasksJob`.
- Produces: A `TEXT NOT NULL` `asana_tasks.name` column; rollback restores `VARCHAR(255) NOT NULL`.

- [ ] **Step 1: Add the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asana_tasks', function (Blueprint $table) {
            $table->text('name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('asana_tasks', function (Blueprint $table) {
            $table->string('name')->change();
        });
    }
};
```

- [ ] **Step 2: Run the focused test to verify it passes**

Run: `php artisan test tests/Feature/Asana/PullJobsTest.php --filter='preserves names longer than 255 characters'`

Expected: PASS with the long name stored exactly.

- [ ] **Step 3: Verify rollback and re-apply**

Run each migration command against a disposable SQLite file created with `mktemp`, passing its path through `DB_DATABASE`, then delete that file after re-applying the migration.

Expected: All commands exit successfully; the latest migration rolls back and reapplies.

### Task 3: QA and production release

**Files:**
- Verify: `database/migrations/2026_07_10_120000_change_asana_task_name_to_text.php`
- Verify: `tests/Feature/Asana/PullJobsTest.php`

**Interfaces:**
- Consumes: Repository test, formatting, static-analysis, build, and deployment workflows.
- Produces: A verified commit on `main` and a successful production deployment.

- [ ] **Step 1: Run automated QA**

Run formatting, static analysis, PHP tests, Node tests, and the asset build as separate commands. Use `php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress` and `php -d memory_limit=512M vendor/bin/pest` because both can exceed the local 128 MB default.

Expected: All commands exit 0, with 488 PHP tests and all 11 Node tests passing.

- [ ] **Step 2: Commit the fix**

```bash
git add database/migrations/2026_07_10_120000_change_asana_task_name_to_text.php tests/Feature/Asana/PullJobsTest.php docs/superpowers/plans/2026-07-10-asana-task-name-overflow.md
git commit -m "Fix Asana task name overflow"
```

- [ ] **Step 3: Merge and deploy**

```bash
git -C /Users/paulhalfpenny/Sites/internal-tools merge --ff-only fix/asana-task-name-overflow
git -C /Users/paulhalfpenny/Sites/internal-tools push origin main
```

Expected: The push triggers `.github/workflows/deploy.yml`, which pulls `main`, installs dependencies, builds assets, runs `php artisan migrate --force`, rebuilds caches, and restarts the queue.

- [ ] **Step 4: Verify production**

Confirm the deployment completed successfully, `https://internal.filter.agency` responds normally, and the production migration is recorded.
