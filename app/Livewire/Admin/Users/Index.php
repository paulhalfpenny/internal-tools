<?php

namespace App\Livewire\Admin\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Locked]
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

    public ?int $editReportsToUserId = null;

    public string $editNotificationsPausedUntil = '';

    public bool $editEmailNotificationsEnabled = true;

    public bool $editSlackNotificationsEnabled = true;

    public ?string $editSlackUserId = null;

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
        $this->editReportsToUserId = $user->reports_to_user_id;
        $this->editNotificationsPausedUntil = $user->notifications_paused_until?->toDateString() ?? '';
        $this->editEmailNotificationsEnabled = $user->email_notifications_enabled;
        $this->editSlackNotificationsEnabled = $user->slack_notifications_enabled;
        $this->editSlackUserId = $user->slack_user_id;
    }

    public function save(): void
    {
        $this->validate([
            'editRole' => 'required|in:user,manager,admin',
            'editRoleTitle' => 'nullable|string|max:255',
            'editDefaultRate' => 'nullable|numeric|min:0',
            'editWeeklyCapacity' => 'required|numeric|min:0|max:168',
            'editReportsToUserId' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', [Role::Manager->value, Role::Admin->value])->where('is_active', true)),
            ],
            'editNotificationsPausedUntil' => 'nullable|date',
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

        if ($this->editReportsToUserId !== null) {
            if ($this->editReportsToUserId === (int) $this->editingId) {
                $this->addError('editReportsToUserId', 'A user cannot report to themselves.');

                return;
            }

            if (in_array($this->editReportsToUserId, $this->descendantIds((int) $this->editingId), true)) {
                $this->addError('editReportsToUserId', 'That choice would create a circular reporting line.');

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
            'reports_to_user_id' => $this->editReportsToUserId,
            'notifications_paused_until' => $this->editNotificationsPausedUntil !== '' ? $this->editNotificationsPausedUntil : null,
            'email_notifications_enabled' => $this->editEmailNotificationsEnabled,
            'slack_notifications_enabled' => $this->editSlackNotificationsEnabled,
        ]);

        $this->editingId = null;
    }

    /**
     * IDs of every user that ultimately reports up to $userId (transitive direct reports).
     *
     * @return array<int>
     */
    private function descendantIds(int $userId): array
    {
        $descendants = [];
        $queue = [$userId];

        while ($queue !== []) {
            $batch = User::query()->whereIn('reports_to_user_id', $queue)->pluck('id')->all();
            $queue = array_values(array_diff($batch, $descendants));
            $descendants = array_merge($descendants, $queue);
        }

        return $descendants;
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function render(): View
    {
        $managerCandidates = collect();
        if ($this->editingId !== null) {
            $excluded = array_merge([(int) $this->editingId], $this->descendantIds((int) $this->editingId));

            $managerCandidates = User::query()
                ->where('is_active', true)
                ->whereIn('role', [Role::Manager->value, Role::Admin->value])
                ->whereNotIn('id', $excluded)
                ->orderBy('name')
                ->get();
        }

        $usersQuery = User::orderBy('name');
        $term = trim($this->search);
        if ($term !== '') {
            $usersQuery->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('role_title', 'like', "%{$term}%");
            });
        }

        return view('livewire.admin.users.index', [
            'users' => $usersQuery->get(),
            'roles' => Role::cases(),
            'managerCandidates' => $managerCandidates,
        ]);
    }
}
