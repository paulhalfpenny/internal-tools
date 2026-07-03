<?php

namespace App\Livewire\Admin\Tasks;

use App\Domain\TimeTracking\ProjectPickerCache;
use App\Models\Task;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $name = '';

    public bool $isDefaultBillable = true;

    public bool $isJdwDefaultBillable = true;

    public string $colour = '#3B82F6';

    public ?int $editingId = null;

    public string $editName = '';

    public bool $editIsDefaultBillable = true;

    public bool $editIsJdwDefaultBillable = true;

    public string $editColour = '#3B82F6';

    public bool $showArchived = false;

    public function create(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'name' => 'required|string|max:255|unique:tasks,name',
            'colour' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $maxSort = Task::max('sort_order') ?? 0;

        Task::create([
            'name' => $this->name,
            'is_default_billable' => $this->isDefaultBillable,
            'is_jdw_default_billable' => $this->isJdwDefaultBillable,
            'colour' => $this->colour,
            'sort_order' => $maxSort + 1,
        ]);

        $this->name = '';
        $this->isDefaultBillable = true;
        $this->isJdwDefaultBillable = true;
        $this->colour = '#3B82F6';
    }

    public function edit(int $taskId): void
    {
        Gate::authorize('access-admin');

        $task = Task::findOrFail($taskId);
        $this->editingId = $taskId;
        $this->editName = $task->name;
        $this->editIsDefaultBillable = $task->is_default_billable;
        $this->editIsJdwDefaultBillable = $task->is_jdw_default_billable;
        $this->editColour = $task->colour;
    }

    public function save(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'editName' => 'required|string|max:255|unique:tasks,name,'.$this->editingId,
            'editColour' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $task = Task::findOrFail((int) $this->editingId);
        $task->update([
            'name' => $this->editName,
            'is_default_billable' => $this->editIsDefaultBillable,
            'is_jdw_default_billable' => $this->editIsJdwDefaultBillable,
            'colour' => $this->editColour,
        ]);
        $this->forgetProjectPickerCachesForTask($task);

        $this->editingId = null;
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function moveUp(int $taskId): void
    {
        Gate::authorize('access-admin');

        $task = Task::findOrFail($taskId);
        $above = Task::where('sort_order', '<', $task->sort_order)
            ->orderByDesc('sort_order')->first();

        if ($above) {
            [$task->sort_order, $above->sort_order] = [$above->sort_order, $task->sort_order];
            $task->save();
            $above->save();
        }
    }

    public function moveDown(int $taskId): void
    {
        Gate::authorize('access-admin');

        $task = Task::findOrFail($taskId);
        $below = Task::where('sort_order', '>', $task->sort_order)
            ->orderBy('sort_order')->first();

        if ($below) {
            [$task->sort_order, $below->sort_order] = [$below->sort_order, $task->sort_order];
            $task->save();
            $below->save();
        }
    }

    public function toggleArchive(int $taskId): void
    {
        Gate::authorize('access-admin');

        $task = Task::findOrFail($taskId);
        $task->update(['is_archived' => ! $task->is_archived]);
        $this->forgetProjectPickerCachesForTask($task);
    }

    public function render(): View
    {
        $query = Task::orderBy('sort_order')->orderBy('name');
        if (! $this->showArchived) {
            $query->where('is_archived', false);
        }

        return view('livewire.admin.tasks.index', [
            'tasks' => $query->get(),
        ]);
    }

    private function forgetProjectPickerCachesForTask(Task $task): void
    {
        $task->loadMissing('projects.users:id');

        ProjectPickerCache::forgetForUsers(
            $task->projects->flatMap(fn ($project) => $project->users->pluck('id'))
        );
    }
}
