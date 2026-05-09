<?php

namespace App\Livewire\Admin\TimeEntries;

use App\Domain\Billing\RateResolver;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class BulkMove extends Component
{
    use WithPagination;

    // Filters
    public ?int $filterClientId = null;

    public ?int $filterProjectId = null;

    public ?int $filterTaskId = null;

    public ?int $filterUserId = null;

    public string $filterFrom = '';

    public string $filterTo = '';

    /** @var array<int, int> */
    public array $selected = [];

    // Destination
    public ?int $destinationProjectId = null;

    public ?int $destinationTaskId = null;

    public ?string $confirmation = null;

    public function mount(): void
    {
        abort_unless(Gate::allows('access-admin'), 403);

        $this->filterFrom = now()->subMonth()->startOfMonth()->toDateString();
        $this->filterTo = now()->endOfMonth()->toDateString();
    }

    public function updating(string $name, mixed $value): void
    {
        // Reset selection when filters change
        if (str_starts_with($name, 'filter')) {
            $this->selected = [];
            $this->resetPage();
        }
    }

    public function updatedFilterClientId(): void
    {
        // Cascade: clear project/task if they don't belong to the new client filter
        $this->filterProjectId = null;
        $this->filterTaskId = null;
    }

    public function move(RateResolver $rateResolver): void
    {
        if ($this->destinationProjectId === null || $this->destinationTaskId === null) {
            $this->confirmation = 'Pick a destination project and task before moving.';

            return;
        }

        if ($this->selected === []) {
            $this->confirmation = 'Select at least one entry to move.';

            return;
        }

        $destProject = Project::with(['tasks', 'users'])->findOrFail($this->destinationProjectId);
        $destTask = Task::findOrFail($this->destinationTaskId);

        if (! $destProject->tasks->contains($destTask->id)) {
            $this->confirmation = 'That task is not assigned to the destination project.';

            return;
        }

        $movedCount = 0;
        $movedHours = 0.0;

        DB::transaction(function () use ($destProject, $destTask, $rateResolver, &$movedCount, &$movedHours): void {
            $entries = TimeEntry::with(['user', 'project'])
                ->whereIn('id', $this->selected)
                ->lockForUpdate()
                ->get();

            $changedBy = (int) auth()->id();
            $now = now();

            foreach ($entries as $entry) {
                $oldProjectId = $entry->project_id;
                $oldTaskId = $entry->task_id;

                if ($oldProjectId === $destProject->id && $oldTaskId === $destTask->id) {
                    continue;
                }

                // Re-resolve billing for the new project context
                $resolution = $rateResolver->resolveWithHours($destProject, $destTask, $entry->user, (float) $entry->hours);

                $entry->update([
                    'project_id' => $destProject->id,
                    'task_id' => $destTask->id,
                    'is_billable' => $resolution->isBillable,
                    'billable_rate_snapshot' => $resolution->rateSnapshot,
                    'billable_amount' => $resolution->billableAmount,
                ]);

                if ($oldProjectId !== $destProject->id) {
                    TimeEntryAudit::create([
                        'time_entry_id' => $entry->id,
                        'changed_by_user_id' => $changedBy,
                        'field' => 'project_id',
                        'old_value' => (string) $oldProjectId,
                        'new_value' => (string) $destProject->id,
                        'created_at' => $now,
                    ]);
                }
                if ($oldTaskId !== $destTask->id) {
                    TimeEntryAudit::create([
                        'time_entry_id' => $entry->id,
                        'changed_by_user_id' => $changedBy,
                        'field' => 'task_id',
                        'old_value' => (string) $oldTaskId,
                        'new_value' => (string) $destTask->id,
                        'created_at' => $now,
                    ]);
                }

                $movedCount++;
                $movedHours += (float) $entry->hours;
            }
        });

        $this->selected = [];
        $this->confirmation = "Moved {$movedCount} entries (".number_format($movedHours, 2).' hours).';
    }

    public function render(): View
    {
        $query = TimeEntry::with(['project.client', 'task', 'user'])
            ->whereBetween('spent_on', [$this->filterFrom, $this->filterTo]);

        if ($this->filterClientId !== null) {
            $clientId = $this->filterClientId;
            $query->whereHas('project', fn ($q) => $q->where('client_id', $clientId));
        }
        if ($this->filterProjectId !== null) {
            $query->where('project_id', $this->filterProjectId);
        }
        if ($this->filterTaskId !== null) {
            $query->where('task_id', $this->filterTaskId);
        }
        if ($this->filterUserId !== null) {
            $query->where('user_id', $this->filterUserId);
        }

        $entries = $query->orderBy('spent_on', 'desc')->orderBy('id', 'desc')->paginate(50);

        $clients = Client::orderBy('name')->get();
        $projects = Project::with('client')->where('is_archived', false)->orderBy('name')->get();
        $tasks = Task::orderBy('name')->get();
        $users = User::where('is_active', true)->orderBy('name')->get();
        $destinationTasks = $this->destinationProjectId !== null
            ? Project::with('tasks')->find($this->destinationProjectId)?->tasks ?? collect()
            : collect();

        $recentMoves = TimeEntryAudit::with(['changedBy', 'timeEntry.project.client'])
            ->where('changed_by_user_id', auth()->id())
            ->where('field', 'project_id')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('livewire.admin.time-entries.bulk-move', [
            'entries' => $entries,
            'clients' => $clients,
            'projects' => $projects,
            'tasks' => $tasks,
            'users' => $users,
            'destinationTasks' => $destinationTasks,
            'recentMoves' => $recentMoves,
        ]);
    }
}
