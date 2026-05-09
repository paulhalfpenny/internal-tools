<?php

namespace App\Livewire\Admin\Clients;

use App\Models\Client;
use App\Models\Task;
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

    public bool $showArchived = false;

    public ?int $editingId = null;

    public string $editName = '';

    public string $editCode = '';

    /** @var array<int, int> */
    public array $editDefaultTaskIds = [];

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:clients,code',
        ]);

        Client::create([
            'name' => $this->name,
            'code' => $this->code ?: null,
        ]);

        $this->name = '';
        $this->code = '';
    }

    public function edit(int $clientId): void
    {
        $client = Client::with('defaultTasks')->findOrFail($clientId);
        $this->editingId = $clientId;
        $this->editName = $client->name;
        $this->editCode = $client->code ?? '';
        $this->editDefaultTaskIds = $client->defaultTasks->pluck('id')->all();
    }

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editCode' => 'nullable|string|max:20|unique:clients,code,'.$this->editingId,
        ]);

        $client = Client::findOrFail((int) $this->editingId);
        $client->update([
            'name' => $this->editName,
            'code' => $this->editCode ?: null,
        ]);

        $sync = [];
        foreach (array_values(array_unique(array_map('intval', $this->editDefaultTaskIds))) as $idx => $taskId) {
            $sync[$taskId] = ['sort_order' => $idx];
        }
        $client->defaultTasks()->sync($sync);

        $this->editingId = null;
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function toggleArchive(int $clientId): void
    {
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
        ]);
    }
}
