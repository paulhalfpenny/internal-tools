<?php

namespace App\Livewire\Admin\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public string $colour = '#2563EB';

    /** @var array<int, int|string> */
    public array $userIds = [];

    public string $userSearch = '';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editDescription = '';

    public string $editColour = '#2563EB';

    /** @var array<int, int|string> */
    public array $editUserIds = [];

    public string $editUserSearch = '';

    public bool $showArchived = false;

    public function create(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'name' => 'required|string|max:255|unique:teams,name',
            'description' => 'nullable|string|max:1000',
            'colour' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'userIds' => 'array',
            'userIds.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('archived_at')),
            ],
        ]);

        $team = Team::create([
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
            'colour' => $this->colour,
        ]);

        $team->users()->sync($this->normalisedUserIds($this->userIds));

        $this->reset(['name', 'description', 'userIds', 'userSearch']);
        $this->colour = '#2563EB';
    }

    public function edit(int $teamId): void
    {
        Gate::authorize('access-admin');

        $team = Team::findOrFail($teamId);
        $this->editingId = $team->id;
        $this->editName = $team->name;
        $this->editDescription = $team->description ?? '';
        $this->editColour = $team->colour;
        $this->editUserIds = $team->users()->pluck('users.id')->map(fn (int $id) => (string) $id)->all();
        $this->editUserSearch = '';
    }

    public function save(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'editName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')->ignore($this->editingId),
            ],
            'editDescription' => 'nullable|string|max:1000',
            'editColour' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'editUserIds' => 'array',
            'editUserIds.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('archived_at')),
            ],
        ]);

        $team = Team::findOrFail((int) $this->editingId);
        $team->update([
            'name' => $this->editName,
            'description' => $this->editDescription !== '' ? $this->editDescription : null,
            'colour' => $this->editColour,
        ]);
        $team->users()->sync($this->normalisedUserIds($this->editUserIds));

        $this->cancel();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->editUserIds = [];
        $this->editUserSearch = '';
        $this->resetErrorBag();
    }

    public function addUser(int $userId): void
    {
        Gate::authorize('access-admin');

        if (! $this->activeUserExists($userId)) {
            $this->addError('userIds', 'Choose an active user.');

            return;
        }

        $this->userIds = collect($this->userIds)
            ->push($userId)
            ->map(fn (int|string $id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $this->userSearch = '';
        $this->resetErrorBag('userIds');
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('access-admin');

        $this->userIds = collect($this->userIds)
            ->map(fn (int|string $id) => (int) $id)
            ->reject(fn (int $id) => $id === $userId)
            ->values()
            ->all();
    }

    public function addEditUser(int $userId): void
    {
        Gate::authorize('access-admin');

        if (! $this->activeUserExists($userId)) {
            $this->addError('editUserIds', 'Choose an active user.');

            return;
        }

        $this->editUserIds = collect($this->editUserIds)
            ->push($userId)
            ->map(fn (int|string $id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $this->editUserSearch = '';
        $this->resetErrorBag('editUserIds');
    }

    public function removeEditUser(int $userId): void
    {
        Gate::authorize('access-admin');

        $this->editUserIds = collect($this->editUserIds)
            ->map(fn (int|string $id) => (int) $id)
            ->reject(fn (int $id) => $id === $userId)
            ->values()
            ->all();
    }

    public function toggleArchive(int $teamId): void
    {
        Gate::authorize('access-admin');

        $team = Team::findOrFail($teamId);
        $team->update(['is_archived' => ! $team->is_archived]);
    }

    public function delete(int $teamId): void
    {
        Gate::authorize('access-admin');

        Team::findOrFail($teamId)->delete();
    }

    public function render(): View
    {
        $query = Team::query()
            ->with(['users' => fn ($query) => $query->orderBy('name')])
            ->withCount('users')
            ->orderBy('name');

        if (! $this->showArchived) {
            $query->where('is_archived', false);
        }

        $users = User::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        $selectedUserIds = $this->normalisedUserIds($this->userIds);
        $selectedEditUserIds = $this->normalisedUserIds($this->editUserIds);

        return view('livewire.admin.teams.index', [
            'teams' => $query->get(),
            'selectedUsers' => $users->whereIn('id', $selectedUserIds)->values(),
            'availableUsers' => $this->availableUsers($users, $selectedUserIds, $this->userSearch),
            'selectedEditUsers' => $users->whereIn('id', $selectedEditUserIds)->values(),
            'availableEditUsers' => $this->availableUsers($users, $selectedEditUserIds, $this->editUserSearch),
        ]);
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return array<int, int>
     */
    private function normalisedUserIds(array $userIds): array
    {
        return collect($userIds)
            ->map(fn (int|string $id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function activeUserExists(int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->exists();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<int, int>  $selectedIds
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function availableUsers($users, array $selectedIds, string $search)
    {
        $term = mb_strtolower(trim($search));

        return $users
            ->reject(fn (User $user) => in_array($user->id, $selectedIds, true))
            ->filter(function (User $user) use ($term) {
                if ($term === '') {
                    return true;
                }

                return str_contains(mb_strtolower($user->name), $term)
                    || str_contains(mb_strtolower($user->email), $term)
                    || str_contains(mb_strtolower($user->role_title ?? ''), $term);
            })
            ->take(8)
            ->values();
    }
}
