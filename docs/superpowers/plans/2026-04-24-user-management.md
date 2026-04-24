# User Management Screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the cramped inline-row editing on Admin → Users with a clean modal, and simplify the list to Name / Email / Role / Active columns.

**Architecture:** Modify `app/Livewire/Admin/Users/Index.php` in-place — add a self-edit safety guard to `save()`. Rebuild `resources/views/livewire/admin/users/index.blade.php` — simple 5-column table plus a modal overlay driven by `$editingId !== null`. Alpine.js handles Escape-to-close and click-outside dismissal; the role dropdown uses `wire:model.live` so the access-description hint updates immediately (Livewire 4).

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js, Tailwind CSS, Pest

---

### Task 1: Feature tests for the admin users screen

**Files:**
- Create: `tests/Feature/Admin/UsersTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

use App\Enums\Role;
use App\Livewire\Admin\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admin cannot access users admin screen', function () {
    $user = User::factory()->create(['role' => Role::User]);

    $this->actingAs($user)->get(route('admin.users'))->assertForbidden();
});

test('manager cannot access users admin screen', function () {
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)->get(route('admin.users'))->assertForbidden();
});

test('admin can access users admin screen', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.users'))->assertOk();
});

test('edit sets editingId and populates fields', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create([
        'role' => Role::User,
        'role_title' => 'Designer',
        'default_hourly_rate' => 55.00,
        'weekly_capacity_hours' => 37.5,
        'is_active' => true,
        'is_contractor' => false,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->assertSet('editingId', $other->id)
        ->assertSet('editRole', 'user')
        ->assertSet('editRoleTitle', 'Designer');
});

test('cancel clears editingId', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->call('cancel')
        ->assertSet('editingId', null);
});

test('admin can change another user role', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['role' => Role::User]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->set('editRole', 'manager')
        ->set('editName', $other->name)
        ->set('editWeeklyCapacity', '37.5')
        ->call('save')
        ->assertSet('editingId', null)
        ->assertHasNoErrors();

    expect($other->fresh()->role)->toBe(Role::Manager);
});

test('admin cannot change their own role', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('editRole', 'user')
        ->set('editName', $admin->name)
        ->set('editWeeklyCapacity', '37.5')
        ->call('save')
        ->assertHasErrors(['editRole']);

    expect($admin->fresh()->role)->toBe(Role::Admin);
});

test('admin cannot deactivate themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('editIsActive', false)
        ->set('editName', $admin->name)
        ->set('editWeeklyCapacity', '37.5')
        ->call('save')
        ->assertHasErrors(['editIsActive']);

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('capacity must be between 0 and 168', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('edit', $other->id)
        ->set('editWeeklyCapacity', '200')
        ->set('editName', $other->name)
        ->call('save')
        ->assertHasErrors(['editWeeklyCapacity']);
});
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
./vendor/bin/pest tests/Feature/Admin/UsersTest.php --ci
```

Expected: multiple failures — `admin cannot change their own role`, `admin cannot deactivate themselves` will fail because the guard doesn't exist yet. Access tests should pass (gate is already in place).

---

### Task 2: Add self-edit safety guard to Livewire component

**Files:**
- Modify: `app/Livewire/Admin/Users/Index.php`

- [ ] **Step 1: Replace the `save()` method**

Open `app/Livewire/Admin/Users/Index.php`. The current `save()` method (lines 43–64) has no self-edit guard. Replace the entire method with:

```php
public function save(): void
{
    $this->validate([
        'editName' => 'required|string|max:255',
        'editRole' => 'required|in:user,manager,admin',
        'editRoleTitle' => 'nullable|string|max:255',
        'editDefaultRate' => 'nullable|numeric|min:0',
        'editWeeklyCapacity' => 'required|numeric|min:0|max:168',
    ]);

    if ((int) $this->editingId === auth()->id()) {
        if ($this->editRole !== Role::Admin->value) {
            $this->addError('editRole', 'You cannot change your own role.');
            return;
        }
        if (! $this->editIsActive) {
            $this->addError('editIsActive', 'You cannot deactivate yourself.');
            return;
        }
    }

    User::findOrFail((int) $this->editingId)->update([
        'name' => $this->editName,
        'role' => Role::from($this->editRole),
        'role_title' => $this->editRoleTitle ?: null,
        'default_hourly_rate' => $this->editDefaultRate !== '' ? (float) $this->editDefaultRate : null,
        'weekly_capacity_hours' => (float) $this->editWeeklyCapacity,
        'is_active' => $this->editIsActive,
        'is_contractor' => $this->editIsContractor,
    ]);

    $this->editingId = null;
}
```

No other changes to this file — only `save()` is modified.

- [ ] **Step 2: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Admin/UsersTest.php --ci
```

Expected: all 9 tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/Admin/Users/Index.php tests/Feature/Admin/UsersTest.php
git commit -m "feat: add self-edit safety guard to admin users component"
```

---

### Task 3: Rebuild the Blade view — simplified list + modal

**Files:**
- Modify: `resources/views/livewire/admin/users/index.blade.php`

- [ ] **Step 1: Replace the entire view**

Overwrite `resources/views/livewire/admin/users/index.blade.php` with:

```blade
<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Users</h1>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Role</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Active</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($users as $user)
                    <tr class="{{ $user->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $user->role->value === 'admin' ? 'bg-red-100 text-red-700' : ($user->role->value === 'manager' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ ucfirst($user->role->value) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($user->is_active)
                                <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                            @else
                                <span class="inline-block w-2 h-2 rounded-full bg-gray-300"></span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $user->id }})" class="text-sm text-blue-600 hover:underline">Edit</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($editingId !== null)
        @php $isSelfEdit = $editingId === auth()->id(); @endphp
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
            wire:click="cancel"
            x-data
            @keydown.escape.window="$wire.cancel()"
        >
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6" @click.stop>
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-base font-semibold text-gray-900">Edit User</h2>
                    <button wire:click="cancel" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Name</label>
                    <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ $editName }}</div>
                </div>

                @php $editingUser = $users->firstWhere('id', $editingId); @endphp
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Email</label>
                    <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-500">{{ $editingUser?->email }}</div>
                </div>

                <div class="mb-1">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Role</label>
                    <select
                        wire:model.live="editRole"
                        class="w-full border border-gray-300 rounded-md text-sm px-3 py-2 {{ $isSelfEdit ? 'opacity-50 cursor-not-allowed bg-gray-50' : '' }}"
                        @disabled($isSelfEdit)
                    >
                        @foreach($roles as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-gray-500 mb-1 pl-0.5">
                    @if($editRole === 'admin')
                        Full access: timesheet, reports, and admin screens.
                    @elseif($editRole === 'manager')
                        Can view reports. Cannot access admin screens.
                    @else
                        Timesheet access only.
                    @endif
                </p>
                @error('editRole')
                    <p class="text-red-600 text-xs mb-1">{{ $message }}</p>
                @enderror
                @if($isSelfEdit)
                    <p class="text-xs text-amber-600 mb-4 mt-1">You cannot change your own role or deactivate yourself.</p>
                @else
                    <div class="mb-4"></div>
                @endif

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Job Title</label>
                    <input wire:model="editRoleTitle" type="text" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Rate (£/hr)</label>
                        <input wire:model="editDefaultRate" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        @error('editDefaultRate')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Capacity (hrs/week)</label>
                        <input wire:model="editWeeklyCapacity" type="number" step="0.5" min="0" max="168" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        @error('editWeeklyCapacity')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="flex gap-5 mb-6">
                    <label class="flex items-center gap-2 text-sm {{ $isSelfEdit ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer' }}">
                        <input
                            wire:model="editIsActive"
                            type="checkbox"
                            class="rounded"
                            @disabled($isSelfEdit)
                        > Active
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="editIsContractor" type="checkbox" class="rounded"> Contractor
                    </label>
                </div>
                @error('editIsActive')<p class="text-red-600 text-xs -mt-4 mb-3">{{ $message }}</p>@enderror

                <div class="flex gap-2">
                    <button wire:click="save" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">Save changes</button>
                    <button wire:click="cancel" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 2: Run the full test suite**

```bash
./vendor/bin/pest --ci
```

Expected: all tests pass (including the 9 in `UsersTest.php`). Pint and Larastan will run in CI — to check locally:

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --no-progress
```

If Pint reports issues, fix them with `./vendor/bin/pint`.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/admin/users/index.blade.php
git commit -m "feat: replace inline user edit with modal, simplify list columns"
```
