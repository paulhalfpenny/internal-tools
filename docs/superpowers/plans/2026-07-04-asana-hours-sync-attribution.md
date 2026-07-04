# Asana Hours-Sync Attribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route the Asana "Hours tracked (Internal Tools)" custom-field writes through a dedicated bot account (`internaltools@filteragency.com`) so changes are attributed honestly instead of to a non-deterministically-chosen admin.

**Architecture:** Add a single "designated sync account" pointer to `users` (a boolean flag with an at-most-one invariant enforced in a model setter). `SyncAsanaTaskHoursJob::pickActor()` prefers that account; if it has no token or its workspace doesn't match, the job falls back to the existing admin-priority actor and logs an `asana.sync_hours.actor_fallback` warning. Admins pick the account from the existing Admin → Asana Settings page.

**Tech Stack:** Laravel 11, Livewire 4, Pest 3, MySQL. Tests run with `php artisan test`.

---

## File Structure

- **Create** `database/migrations/2026_07_04_120000_add_is_asana_sync_actor_to_users_table.php` — adds the `is_asana_sync_actor` column.
- **Modify** `app/Models/User.php` — add the column to `$fillable`/`casts`, plus `asanaSyncActor()` and `designateAsanaSyncActor()` static helpers.
- **Modify** `app/Jobs/Asana/SyncAsanaTaskHoursJob.php` — rewrite `pickActor()` to prefer the designated account with warn-and-fallback.
- **Modify** `app/Livewire/Admin/Integrations/AsanaSettings.php` — expose/allow setting the designated account and a "fell back recently" flag.
- **Modify** `resources/views/livewire/admin/integrations/asana.blade.php` — sync-account selector + warning banner.
- **Modify** `docs/asana-deployment.md` — document the one-time bot-account setup.
- **Test** `tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`, `tests/Feature/Asana/AsanaSyncActorTest.php`, `tests/Feature/Asana/AdminAsanaSettingsAuthTest.php`.

---

## Task 1: Designation store (migration + User model)

**Files:**
- Create: `database/migrations/2026_07_04_120000_add_is_asana_sync_actor_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Asana/AsanaSyncActorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Asana/AsanaSyncActorTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('asanaSyncActor returns the flagged user or null', function () {
    expect(User::asanaSyncActor())->toBeNull();

    $bot = User::factory()->create();
    User::designateAsanaSyncActor($bot);

    expect(User::asanaSyncActor()?->id)->toBe($bot->id);
});

test('designating a sync actor clears the previous one', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    User::designateAsanaSyncActor($first);
    User::designateAsanaSyncActor($second);

    expect(User::query()->where('is_asana_sync_actor', true)->pluck('id')->all())
        ->toBe([$second->id]);
});

test('designating null clears any existing sync actor', function () {
    $bot = User::factory()->create();
    User::designateAsanaSyncActor($bot);

    User::designateAsanaSyncActor(null);

    expect(User::asanaSyncActor())->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Asana/AsanaSyncActorTest.php`
Expected: FAIL — `Call to undefined method App\Models\User::designateAsanaSyncActor()` (and a missing-column error).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_04_120000_add_is_asana_sync_actor_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_asana_sync_actor')->default(false)->after('asana_workspace_gid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_asana_sync_actor');
        });
    }
};
```

- [ ] **Step 4: Update the User model**

In `app/Models/User.php`, add `'is_asana_sync_actor'` to the `$fillable` array (after `'asana_workspace_gid'`), and add `'is_asana_sync_actor' => 'boolean',` to the array returned by `casts()` (after the `asana_token_expires_at` cast).

Add this import near the other `use` statements at the top of the file:

```php
use Illuminate\Support\Facades\DB;
```

Add these two static methods to the class (place them just after the `asanaConnected()` method):

```php
public static function asanaSyncActor(): ?self
{
    return static::query()->where('is_asana_sync_actor', true)->first();
}

public static function designateAsanaSyncActor(?self $user): void
{
    DB::transaction(function () use ($user) {
        static::query()->where('is_asana_sync_actor', true)->update(['is_asana_sync_actor' => false]);

        if ($user !== null) {
            $user->forceFill(['is_asana_sync_actor' => true])->save();
        }
    });
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Asana/AsanaSyncActorTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_04_120000_add_is_asana_sync_actor_to_users_table.php app/Models/User.php tests/Feature/Asana/AsanaSyncActorTest.php
git commit -m "Add designated Asana sync-actor flag to users

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Actor selection in SyncAsanaTaskHoursJob

**Files:**
- Modify: `app/Jobs/Asana/SyncAsanaTaskHoursJob.php:158-167` (the `pickActor` method)
- Test: `tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`

The test file already has helpers `asanaTestLinkedProject()`, `asanaTestEnsureCachedTask()`, `asanaTestConnectedAdmin()`, and `asanaTestEntry()` (workspace `WS1`, board `P1`, custom field `F1`). Reuse them. The admin helper creates a user with token `tok`; new tests below create a bot with token `bottok`. The bearer token used for the API call is the actor's `asana_access_token`, so assertions check the `Authorization` header.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`:

```php
test('prefers the designated sync actor over admins', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok"
    $bot = User::factory()->create([
        'role' => Role::User,
        'asana_access_token' => 'bottok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::designateAsanaSyncActor($bot);

    $task = Task::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $bot, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/T1')
        && $r->hasHeader('Authorization', 'Bearer bottok'));

    expect(App\Models\AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->exists())
        ->toBeFalse();
});

test('falls back to an admin and warns when the designated actor has no token', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok"
    $bot = User::factory()->create([
        'role' => Role::User,
        'asana_access_token' => null,
        'asana_workspace_gid' => 'WS1',
    ]);
    User::designateAsanaSyncActor($bot);

    $task = Task::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $bot, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));

    $log = App\Models\AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->first();
    expect($log)->not->toBeNull();
    expect($log->context['reason'])->toBe('actor_no_token');
});

test('falls back and warns when the designated actor is in another workspace', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok", workspace WS1
    $bot = User::factory()->create([
        'role' => Role::User,
        'asana_access_token' => 'bottok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS-OTHER',
    ]);
    User::designateAsanaSyncActor($bot);

    $task = Task::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $bot, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));

    $log = App\Models\AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->first();
    expect($log?->context['reason'])->toBe('actor_workspace_mismatch');
});

test('does not warn on fallback when no sync actor is designated', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok"
    $task = Task::factory()->create();

    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, asanaTestConnectedAdmin(), 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    expect(App\Models\AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->exists())
        ->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`
Expected: the four new tests FAIL — the "prefers" test sends `Bearer tok` (current behaviour ignores the designated actor), and the fallback tests find no `actor_fallback` log.

- [ ] **Step 3: Rewrite `pickActor`**

In `app/Jobs/Asana/SyncAsanaTaskHoursJob.php`, replace the existing `pickActor` method (lines 158-167) with:

```php
private function pickActor(string $workspaceGid): ?User
{
    $designated = User::asanaSyncActor();

    if ($designated !== null
        && $designated->asana_access_token !== null
        && $designated->asana_workspace_gid === $workspaceGid) {
        return $designated;
    }

    $fallback = User::query()
        ->whereNotNull('asana_access_token')
        ->whereNotNull('asana_user_gid')
        ->where('asana_workspace_gid', $workspaceGid)
        ->where('is_active', true)
        ->orderByRaw('CASE WHEN role = "admin" THEN 0 WHEN role = "manager" THEN 1 ELSE 2 END')
        ->first();

    if ($designated !== null) {
        AsanaSyncLog::warn('asana.sync_hours.actor_fallback', [
            'asana_task_gid' => $this->asanaTaskGid,
            'project_id' => $this->projectId,
            'designated_user_id' => $designated->id,
            'reason' => $designated->asana_access_token === null
                ? 'actor_no_token'
                : 'actor_workspace_mismatch',
        ]);
    }

    return $fallback;
}
```

(`AsanaSyncLog` and `User` are already imported at the top of this file.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php`
Expected: PASS (all tests in the file, including the original ones).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Asana/SyncAsanaTaskHoursJob.php tests/Feature/Asana/SyncAsanaTaskHoursJobTest.php
git commit -m "Prefer designated bot account for Asana hours sync

Falls back to the admin-priority actor with a warning when the
designated account is missing a token or is in another workspace.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Admin UI for choosing the sync account

**Files:**
- Modify: `app/Livewire/Admin/Integrations/AsanaSettings.php`
- Modify: `resources/views/livewire/admin/integrations/asana.blade.php`
- Test: `tests/Feature/Asana/AdminAsanaSettingsAuthTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Asana/AdminAsanaSettingsAuthTest.php`:

```php
test('admin can designate a connected account as the sync actor', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $bot = User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS1',
    ]);

    $this->actingAs($admin);

    Livewire::test(AsanaSettings::class)
        ->set('syncActorUserId', $bot->id);

    expect(User::asanaSyncActor()?->id)->toBe($bot->id);
});

test('admin can clear the sync actor', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $bot = User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::designateAsanaSyncActor($bot);

    $this->actingAs($admin);

    Livewire::test(AsanaSettings::class)
        ->set('syncActorUserId', null);

    expect(User::asanaSyncActor())->toBeNull();
});

test('settings page flags a recent actor fallback', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    AsanaSyncLog::warn('asana.sync_hours.actor_fallback', ['reason' => 'actor_no_token']);

    $this->actingAs($admin);

    expect(Livewire::test(AsanaSettings::class)->viewData('syncActorFallbackRecently'))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Asana/AdminAsanaSettingsAuthTest.php`
Expected: the three new tests FAIL — `syncActorUserId` and `syncActorFallbackRecently` don't exist yet.

- [ ] **Step 3: Update the Livewire component**

In `app/Livewire/Admin/Integrations/AsanaSettings.php`:

Add a public property after the class opening brace (before `mount()`):

```php
public ?int $syncActorUserId = null;
```

Replace the `mount()` method with:

```php
public function mount(): void
{
    Gate::authorize('access-admin');
    $this->syncActorUserId = User::asanaSyncActor()?->id;
}
```

Add this hook method after `mount()`:

```php
public function updatedSyncActorUserId(mixed $value): void
{
    Gate::authorize('access-admin');

    $user = $value
        ? User::query()->whereNotNull('asana_access_token')->find((int) $value)
        : null;

    User::designateAsanaSyncActor($user);

    session()->flash('asana_status', $user
        ? 'Asana sync account set to '.$user->name.'.'
        : 'Asana sync account cleared.');
}
```

In `render()`, add these two keys to the array passed to `view(...)`:

```php
'syncActorFallbackRecently' => AsanaSyncLog::query()
    ->where('event', 'asana.sync_hours.actor_fallback')
    ->where('created_at', '>=', now()->subDay())
    ->exists(),
'syncActorUserId' => $this->syncActorUserId,
```

- [ ] **Step 4: Update the Blade view**

In `resources/views/livewire/admin/integrations/asana.blade.php`, inside the "Connected users" card, immediately after the closing `@endif` of the `$connectedUsers` block (the `@endif` on the line before `</div>` at line ~82), add:

```blade
<div class="mt-4 pt-4 border-t border-gray-100">
    <label for="syncActor" class="block text-xs font-medium text-gray-700 mb-1">
        Asana sync account
    </label>
    <p class="text-xs text-gray-500 mb-2">
        Hours-tracked updates in Asana are attributed to this account. Leave as
        “None” to fall back to an admin.
    </p>
    <select id="syncActor" wire:model.live="syncActorUserId"
            class="w-full max-w-sm text-sm border-gray-300 rounded">
        <option value="">None (fall back to an admin)</option>
        @foreach($connectedUsers as $u)
            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
        @endforeach
    </select>
</div>
```

Then, immediately after the opening `<div ...>` of the whole component (near the top, before the `session('asana_status')` block at line ~7), add the fallback banner:

```blade
@if($syncActorFallbackRecently)
    <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        The designated Asana sync account is disconnected — hours are being
        attributed to an admin until it is reconnected. Reconnect it, then
        reselect it below.
    </div>
@endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Asana/AdminAsanaSettingsAuthTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Admin/Integrations/AsanaSettings.php resources/views/livewire/admin/integrations/asana.blade.php tests/Feature/Asana/AdminAsanaSettingsAuthTest.php
git commit -m "Add Asana sync-account picker and fallback banner to admin settings

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Document the bot-account setup

**Files:**
- Modify: `docs/asana-deployment.md`

- [ ] **Step 1: Add a setup section**

Append the following section to `docs/asana-deployment.md`:

```markdown
## Dedicated hours-sync account

Hours-tracked updates pushed to Asana are attributed to whichever account is
designated as the **sync account** in Admin → Asana Settings. Use a dedicated
bot account so changes aren't credited to a random admin.

One-time setup:

1. Create the Asana account `internaltools@filteragency.com`.
2. Add it to the Filter workspace and to every board whose projects sync hours,
   and authorise the Internal Tools Asana app for it.
3. Sign into Internal Tools as that account and connect Asana via the profile
   page (the normal OAuth flow).
4. As an admin, open **Admin → Asana Settings** and select the bot account under
   **Asana sync account**.

If the bot account's token later expires or is revoked, hours keep syncing under
an admin's identity and the settings page shows a warning banner
(`asana.sync_hours.actor_fallback` in the sync log). Reconnect the bot account
and reselect it to restore correct attribution.
```

- [ ] **Step 2: Commit**

```bash
git add docs/asana-deployment.md
git commit -m "Document dedicated Asana hours-sync account setup

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification

- [ ] **Run the full Asana test suite**

Run: `php artisan test tests/Feature/Asana`
Expected: all green.

- [ ] **Run static analysis** (the repo uses Larastan/PHPStan)

Run: `./vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no new errors in the changed files.

- [ ] **Local test-it-yourself handoff (for the user)**

Start the app (Herd serves `https://internal-tools.test`), open `/demo-login` to land authenticated as an admin, then go to **Admin → Asana Settings** and confirm:
- the **Asana sync account** selector lists connected accounts and persists a choice;
- selecting "None" clears it;
- (optional) after seeding an `asana.sync_hours.actor_fallback` log within the last day, the amber warning banner appears.
```
