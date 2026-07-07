<?php

namespace App\Livewire\Admin\Clients;

use App\Domain\TimeTracking\ProjectPickerCache;
use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use App\Models\Task;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public string $name = '';

    public string $code = '';

    public string $taskBillabilityProfile = 'agency';

    public bool $showArchived = false;

    public ?int $editingId = null;

    public string $editName = '';

    public string $editCode = '';

    public string $editTaskBillabilityProfile = 'agency';

    /** @var array<int, string> */
    public array $editDefaultTaskIds = [];

    public function create(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:clients,code',
            'taskBillabilityProfile' => 'required|in:agency,jdw',
        ]);

        Client::create([
            'name' => $this->name,
            'code' => $this->code ?: null,
            'task_billability_profile' => $this->taskBillabilityProfile,
        ]);

        $this->name = '';
        $this->code = '';
        $this->taskBillabilityProfile = ClientTaskBillabilityProfile::Agency->value;
    }

    public function edit(int $clientId): void
    {
        Gate::authorize('access-admin');

        $client = Client::with('defaultTasks')->findOrFail($clientId);
        $this->editingId = $clientId;
        $this->editName = $client->name;
        $this->editCode = $client->code ?? '';
        $this->editTaskBillabilityProfile = $client->task_billability_profile->value;
        $this->editDefaultTaskIds = $client->defaultTasks
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->all();
    }

    public function save(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'editName' => 'required|string|max:255',
            'editCode' => 'nullable|string|max:20|unique:clients,code,'.$this->editingId,
            'editTaskBillabilityProfile' => 'required|in:agency,jdw',
        ]);

        $client = Client::findOrFail((int) $this->editingId);
        $taskBillabilityProfileChanged = $client->task_billability_profile->value !== $this->editTaskBillabilityProfile;

        $client->update([
            'name' => $this->editName,
            'code' => $this->editCode ?: null,
            'task_billability_profile' => $this->editTaskBillabilityProfile,
        ]);

        $sync = [];
        foreach (array_values(array_unique(array_map('intval', $this->editDefaultTaskIds))) as $idx => $taskId) {
            $sync[$taskId] = ['sort_order' => $idx];
        }
        $client->defaultTasks()->sync($sync);

        if ($taskBillabilityProfileChanged) {
            $client->reapplyTaskBillabilityToProjects();
            $this->forgetProjectPickerCachesForClient($client);
        }

        $this->editingId = null;
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function toggleArchive(int $clientId): void
    {
        Gate::authorize('access-admin');

        $client = Client::findOrFail($clientId);
        $client->update(['is_archived' => ! $client->is_archived]);
    }

    public function render(): View
    {
        $query = Client::orderBy('name');
        if (! $this->showArchived) {
            $query->where('is_archived', false);
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        return view('livewire.admin.clients.index', [
            'clients' => $query->get(),
            'allTasks' => Task::orderBy('name')->get(),
            'taskBillabilityProfiles' => ClientTaskBillabilityProfile::cases(),
        ]);
    }

    private function forgetProjectPickerCachesForClient(Client $client): void
    {
        $client->loadMissing('projects.users:id');

        ProjectPickerCache::forgetForUsers(
            $client->projects->flatMap(fn ($project) => $project->users->pluck('id'))
        );
    }
}
