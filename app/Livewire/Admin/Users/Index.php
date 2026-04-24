<?php

namespace App\Livewire\Admin\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public ?int $editingId = null;

    public string $editName = ''; // display only — name is sourced from Google OAuth, not editable

    #[Locked]
    public string $editEmail = '';

    public string $editRole = '';

    public string $editRoleTitle = '';

    public string $editDefaultRate = '';

    public string $editWeeklyCapacity = '';

    public bool $editIsActive = true;

    public bool $editIsContractor = false;

    public function edit(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingId = $userId;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editRole = $user->role->value;
        $this->editRoleTitle = $user->role_title ?? '';
        $this->editDefaultRate = $user->default_hourly_rate !== null ? (string) $user->default_hourly_rate : '';
        $this->editWeeklyCapacity = (string) $user->weekly_capacity_hours;
        $this->editIsActive = $user->is_active;
        $this->editIsContractor = $user->is_contractor;
    }

    public function save(): void
    {
        $this->validate([
            'editRole' => 'required|in:user,manager,admin',
            'editRoleTitle' => 'nullable|string|max:255',
            'editDefaultRate' => 'nullable|numeric|min:0',
            'editWeeklyCapacity' => 'required|numeric|min:0|max:168',
        ]);

        if ((int) $this->editingId === auth()->id()) {
            if ($this->editRole !== Role::Admin->value) {
                $this->addError('editRole', 'You cannot change your own role.');
            }
            if (! $this->editIsActive) {
                $this->addError('editIsActive', 'You cannot deactivate yourself.');
            }
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        User::findOrFail((int) $this->editingId)->update([
            'role' => Role::from($this->editRole),
            'role_title' => $this->editRoleTitle ?: null,
            'default_hourly_rate' => $this->editDefaultRate !== '' ? (float) $this->editDefaultRate : null,
            'weekly_capacity_hours' => (float) $this->editWeeklyCapacity,
            'is_active' => $this->editIsActive,
            'is_contractor' => $this->editIsContractor,
        ]);

        $this->editingId = null;
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function render(): View
    {
        return view('livewire.admin.users.index', [
            'users' => User::orderBy('name')->get(),
            'roles' => Role::cases(),
        ]);
    }
}
