<?php

namespace App\Livewire\Timesheet;

use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class WeekView extends Component
{
    #[Url(as: 'date')]
    public string $selectedDate = '';

    #[Locked]
    public ?int $viewedUserId = null;

    #[Locked]
    public bool $isImpersonating = false;

    #[Locked]
    public bool $isReadOnly = false;

    #[Locked]
    public string $backUrl = '';

    #[Locked]
    public string $backLabel = '';

    /**
     * Cell values keyed by "{projectId}:{taskId}" → array of 7 strings (Mon..Sun).
     * Strings so we can hold the user's typed input ("0:30", "1.5", "30m") and only
     * parse on save. Empty string == no entry.
     *
     * @var array<string, array<int, string>>
     */
    public array $cellValues = [];

    /**
     * Rows the admin has added via the "+ Add row" modal that don't have any
     * entries yet. Stored as ["{projectId}:{taskId}", ...]. Persists across
     * Livewire requests within the same week.
     *
     * @var array<int, string>
     */
    public array $extraRows = [];

    // Add-row modal state
    public bool $showAddRowModal = false;

    public ?int $newRowProjectId = null;

    public ?int $newRowTaskId = null;

    public string $newRowAsanaTaskGid = '';

    private ?User $viewedUserCache = null;

    public function mount(?User $user = null): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->selectedDate)) {
            $this->selectedDate = Carbon::today()->toDateString();
        }

        if ($user !== null && $user->exists && $user->id !== auth()->id()) {
            /** @var User $authUser */
            $authUser = auth()->user();
            $cameFromAdmin = (bool) (request()->route()?->getName() === 'admin.timesheets.user.week');

            if ($authUser->isAdmin() && $cameFromAdmin) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('admin.timesheets');
                $this->backLabel = 'Back to admin index';
            } elseif ($authUser->isAdmin() && $user->reports_to_user_id === $authUser->id) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($user->reports_to_user_id === $authUser->id) {
                $this->viewedUserId = $user->id;
                $this->isReadOnly = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($authUser->isAdmin()) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } else {
                abort(403);
            }
        }
    }

    protected function viewedUser(): User
    {
        if ($this->viewedUserCache !== null) {
            return $this->viewedUserCache;
        }
        if ($this->viewedUserId !== null) {
            $user = User::find($this->viewedUserId);
            if ($user) {
                return $this->viewedUserCache = $user;
            }
        }
        /** @var User $authUser */
        $authUser = auth()->user();

        return $this->viewedUserCache = $authUser;
    }

    public function previousWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->subWeek()->toDateString();
        $this->extraRows = [];
        $this->cellValues = [];
    }

    public function nextWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->addWeek()->toDateString();
        $this->extraRows = [];
        $this->cellValues = [];
    }

    public function goToToday(): void
    {
        $this->selectedDate = Carbon::today()->toDateString();
        $this->extraRows = [];
        $this->cellValues = [];
    }

    // --- Add-row modal ---

    public function openAddRowModal(): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $this->resetErrorBag();
        $this->newRowProjectId = null;
        $this->newRowTaskId = null;
        $this->newRowAsanaTaskGid = '';
        $this->showAddRowModal = true;
    }

    public function closeAddRowModal(): void
    {
        $this->showAddRowModal = false;
        $this->newRowProjectId = null;
        $this->newRowTaskId = null;
        $this->newRowAsanaTaskGid = '';
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        if ($this->isReadOnly) {
            return;
        }
        if ($this->newRowProjectId === null || $this->newRowTaskId === null) {
            return;
        }

        // Asana validation — mirrors DayView::validateAsanaTaskRequirement().
        $project = Project::find($this->newRowProjectId);
        if ($project && $project->asanaLinked()) {
            if (! $this->asanaIntegrationAvailable()) {
                $this->addError('newRowAsanaTaskGid',
                    'Asana integration is not active. An admin needs to connect Asana before time can be logged on linked projects.'
                );

                return;
            }
            if ($this->newRowAsanaTaskGid === '') {
                $this->addError('newRowAsanaTaskGid', 'Pick the Asana task this row relates to.');

                return;
            }
            $exists = AsanaTask::where('gid', $this->newRowAsanaTaskGid)
                ->where('asana_project_gid', $project->asana_project_gid)
                ->exists();
            if (! $exists) {
                $this->addError('newRowAsanaTaskGid', 'That Asana task is no longer in this project. Refresh tasks and try again.');

                return;
            }
        }

        $key = $this->buildRowKey($this->newRowProjectId, $this->newRowTaskId, $this->newRowAsanaTaskGid !== '' ? $this->newRowAsanaTaskGid : null);

        // Don't duplicate a row that already exists (either from saved entries
        // or already added in this session).
        if (isset($this->cellValues[$key])) {
            $this->closeAddRowModal();

            return;
        }

        $this->extraRows[] = $key;
        $this->cellValues[$key] = array_fill(0, 7, '');
        $this->closeAddRowModal();
    }

    private function asanaIntegrationAvailable(): bool
    {
        return User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->where('is_active', true)
            ->exists();
    }

    public function removeRow(string $rowKey): void
    {
        if ($this->isReadOnly) {
            return;
        }

        // If the row had any saved entries this week, delete them.
        [$projectId, $taskId, $asanaGid] = $this->parseRowKey($rowKey);
        if ($projectId !== null && $taskId !== null) {
            $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();
            $query = TimeEntry::where('user_id', $this->viewedUser()->id)
                ->where('project_id', $projectId)
                ->where('task_id', $taskId)
                ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()]);
            if ($asanaGid === null) {
                $query->whereNull('asana_task_gid');
            } else {
                $query->where('asana_task_gid', $asanaGid);
            }
            $query->get()->each(fn (TimeEntry $entry) => app(TimeEntryService::class)->delete($entry));
        }

        unset($this->cellValues[$rowKey]);
        $this->extraRows = array_values(array_filter($this->extraRows, fn ($k) => $k !== $rowKey));
    }

    // --- Save ---

    public function save(): void
    {
        if ($this->isReadOnly) {
            return;
        }

        $user = $this->viewedUser();
        $service = app(TimeEntryService::class);
        $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();

        // Pull every entry in the week so we can update/delete in-place.
        $existing = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->get()
            ->groupBy(fn (TimeEntry $e) => $this->buildRowKey($e->project_id, $e->task_id, $e->asana_task_gid).'|'.$e->spent_on->toDateString());

        foreach ($this->cellValues as $rowKey => $perDay) {
            [$projectId, $taskId, $asanaGid] = $this->parseRowKey($rowKey);
            if ($projectId === null || $taskId === null) {
                continue;
            }

            for ($i = 0; $i < 7; $i++) {
                $date = $weekStart->addDays($i)->toDateString();
                $raw = trim((string) ($perDay[$i] ?? ''));
                $cellKey = $rowKey.'|'.$date;
                $existingForCell = $existing->get($cellKey, collect())->first();

                if ($raw === '' || $raw === '0' || $raw === '0:00') {
                    // Empty cell: delete any existing entry for this slot.
                    if ($existingForCell) {
                        $service->delete($existingForCell);
                    }

                    continue;
                }

                try {
                    $hours = HoursParser::parse($raw);
                } catch (\InvalidArgumentException) {
                    continue; // skip invalid input silently
                }

                if ($existingForCell) {
                    $service->update($existingForCell, ['hours' => $hours]);
                } else {
                    $service->create($user, [
                        'project_id' => $projectId,
                        'task_id' => $taskId,
                        'spent_on' => $date,
                        'hours' => $hours,
                        'notes' => null,
                        'asana_task_gid' => $asanaGid,
                    ]);
                }
            }
        }

        // Clear extraRows now that they're persisted; render() will pick them
        // up again from the database on the next render.
        $this->extraRows = [];
        session()->flash('week_saved', true);
    }

    /**
     * Row keys: "{projectId}:{taskId}:{asanaGid|''}". Asana segment may be empty.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function parseRowKey(string $rowKey): array
    {
        $parts = explode(':', $rowKey, 3);
        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return [null, null, null];
        }
        $asana = $parts[2] ?? '';

        return [(int) $parts[0], (int) $parts[1], $asana === '' ? null : $asana];
    }

    private function buildRowKey(int $projectId, int $taskId, ?string $asanaGid): string
    {
        return $projectId.':'.$taskId.':'.($asanaGid ?? '');
    }

    public function render(): View
    {
        $user = $this->viewedUser();
        $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();
        $weekDays = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset));

        $weekEntries = TimeEntry::with(['project.client', 'task'])
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->get();

        // Pull projects (with client + tasks) the user has access to.
        // Note: separate cache key from DayView's "projects_picker_{id}" which
        // caches a different (array) shape for its own picker.
        $projects = Cache::remember(
            "projects_picker_eloquent_{$user->id}",
            now()->addMinutes(10),
            fn () => Project::with(['client', 'tasks'])
                ->where('is_archived', false)
                ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('name')
                ->get()
        );

        // Asana task name lookup, keyed by gid, scoped to projects on this week
        // (so we can label rows that have an Asana task attached).
        $linkedAsanaProjectGids = $projects->pluck('asana_project_gid')->filter()->unique()->values()->all();
        $asanaTasksByGid = AsanaTask::query()
            ->whereIn('asana_project_gid', $linkedAsanaProjectGids)
            ->orderBy('name')
            ->get(['gid', 'asana_project_gid', 'name'])
            ->keyBy('gid');

        // Group into rows by (project, task, asana_task_gid). Each row gets
        // project/task names for display + a 7-cell array of saved hours strings.
        $rowsFromEntries = [];
        foreach ($weekEntries as $entry) {
            $key = $this->buildRowKey($entry->project_id, $entry->task_id, $entry->asana_task_gid);
            if (! isset($rowsFromEntries[$key])) {
                $rowsFromEntries[$key] = [
                    'key' => $key,
                    'project_name' => $entry->project?->name ?? 'Unknown project',
                    'client_name' => $entry->project?->client?->name,
                    'task_name' => $entry->task?->name ?? 'Unknown task',
                    'asana_task_name' => $entry->asana_task_gid ? ($asanaTasksByGid[$entry->asana_task_gid]->name ?? null) : null,
                    'cells' => array_fill(0, 7, ''),
                ];
            }
            $dayIndex = (int) $weekStart->diffInDays(CarbonImmutable::parse($entry->spent_on));
            if ($dayIndex >= 0 && $dayIndex < 7) {
                $rowsFromEntries[$key]['cells'][$dayIndex] = HoursFormatter::asTime((float) $entry->hours);
            }
        }

        foreach ($this->extraRows as $extraKey) {
            if (isset($rowsFromEntries[$extraKey])) {
                continue;
            }
            [$projectId, $taskId, $asanaGid] = $this->parseRowKey($extraKey);
            if ($projectId === null || $taskId === null) {
                continue;
            }
            $project = $projects->firstWhere('id', $projectId);
            $task = $project?->tasks->firstWhere('id', $taskId);
            if (! $project || ! $task) {
                continue;
            }
            $rowsFromEntries[$extraKey] = [
                'key' => $extraKey,
                'project_name' => $project->name,
                'client_name' => $project->client?->name,
                'task_name' => $task->name,
                'asana_task_name' => $asanaGid ? ($asanaTasksByGid[$asanaGid]->name ?? null) : null,
                'cells' => array_fill(0, 7, ''),
            ];
        }

        // Seed the Livewire $cellValues from the database for any row we
        // haven't already touched in this session. This lets the user edit a
        // pre-existing cell, navigate weeks, or hit Save without losing data.
        foreach ($rowsFromEntries as $rowKey => $row) {
            if (! isset($this->cellValues[$rowKey])) {
                $this->cellValues[$rowKey] = $row['cells'];
            } else {
                // Ensure the array always has 7 slots (defensive after wire reset).
                $this->cellValues[$rowKey] = $this->cellValues[$rowKey] + array_fill(0, 7, '');
                ksort($this->cellValues[$rowKey]);
            }
        }

        // Drop cellValues for rows that no longer exist (e.g. removed).
        foreach (array_keys($this->cellValues) as $rowKey) {
            if (! isset($rowsFromEntries[$rowKey])) {
                unset($this->cellValues[$rowKey]);
            }
        }

        // Sort rows alphabetically by client / project / task for consistency.
        $sortedRows = collect($rowsFromEntries)
            ->sortBy(fn ($row) => ($row['client_name'] ?? '').'|'.$row['project_name'].'|'.$row['task_name'])
            ->values()
            ->all();

        // Per-day totals across all rows (live: derived from $cellValues, not DB).
        $dayTotals = array_fill(0, 7, 0.0);
        foreach ($this->cellValues as $perDay) {
            for ($i = 0; $i < 7; $i++) {
                $raw = trim((string) ($perDay[$i] ?? ''));
                if ($raw === '') {
                    continue;
                }
                try {
                    $dayTotals[$i] += HoursParser::parse($raw);
                } catch (\InvalidArgumentException) {
                    // ignore invalid input in totals
                }
            }
        }
        $weekTotal = array_sum($dayTotals);

        // Direct reports for the Team Timesheets dropdown
        $teamMembers = collect();
        $authUser = auth()->user();
        if ($authUser !== null && $this->viewedUserId === null) {
            $teamMembers = $authUser->directReports()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $asanaTasksByProject = AsanaTask::query()
            ->whereIn('asana_project_gid', $linkedAsanaProjectGids)
            ->where('is_completed', false)
            ->orderBy('name')
            ->get(['gid', 'asana_project_gid', 'name'])
            ->groupBy('asana_project_gid')
            ->map(fn ($group) => $group->map(fn (AsanaTask $t) => [
                'gid' => $t->gid,
                'name' => $t->name,
            ])->values()->all())
            ->all();

        return view('livewire.timesheet.week-view', [
            'weekStart' => $weekStart,
            'weekDays' => $weekDays,
            'rows' => $sortedRows,
            'dayTotals' => $dayTotals,
            'weekTotal' => $weekTotal,
            'projectsForPicker' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'client_name' => $p->client?->name ?? '',
                'asana_project_gid' => $p->asana_project_gid,
                'tasks' => $p->tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'colour' => $t->colour,
                    'is_billable' => (bool) $t->pivot->getAttribute('is_billable'),
                ])->values()->all(),
            ])->values()->all(),
            'asanaTasksByProject' => $asanaTasksByProject,
            'asanaAvailable' => $this->asanaIntegrationAvailable(),
            'teamMembers' => $teamMembers,
            'viewedUser' => $user,
        ]);
    }
}
