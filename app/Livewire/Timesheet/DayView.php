<?php

namespace App\Livewire\Timesheet;

use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class DayView extends Component
{
    public string $selectedDate;

    // Modal state
    public bool $showModal = false;

    public ?int $editingEntryId = null;

    // Step 1 — project picker
    public string $projectSearch = '';

    public ?int $selectedProjectId = null;

    // Step 2 — task picker
    public string $taskSearch = '';

    public ?int $selectedTaskId = null;

    // Entry form fields
    public string $hoursInput = '';

    public string $notes = '';

    public string $entryDate = '';

    public string $hoursError = '';

    public function mount(): void
    {
        $this->selectedDate = Carbon::today()->toDateString();
        $this->entryDate = $this->selectedDate;
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
    }

    public function openNewModal(): void
    {
        $this->resetModal();
        $this->entryDate = $this->selectedDate;
        $this->showModal = true;
    }

    public function openEditModal(int $entryId): void
    {
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        $this->resetModal();
        $this->editingEntryId = $entryId;
        $this->selectedProjectId = $entry->project_id;
        $this->selectedTaskId = $entry->task_id;
        $this->hoursInput = (string) $entry->hours;
        $this->notes = $entry->notes ?? '';
        $this->entryDate = $entry->spent_on->toDateString();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetModal();
    }

    public function selectProject(int $projectId): void
    {
        $this->selectedProjectId = $projectId;
        $this->selectedTaskId = null;
        $this->taskSearch = '';
    }

    public function selectTask(int $taskId): void
    {
        $this->selectedTaskId = $taskId;
    }

    public function backToProjectPicker(): void
    {
        $this->selectedProjectId = null;
        $this->selectedTaskId = null;
        $this->projectSearch = '';
        $this->taskSearch = '';
    }

    public function save(): void
    {
        $this->hoursError = '';

        $this->validate([
            'selectedProjectId' => 'required|integer',
            'selectedTaskId' => 'required|integer',
            'hoursInput' => 'required|string',
            'entryDate' => 'required|date',
        ]);

        try {
            $hours = HoursParser::parse($this->hoursInput);
        } catch (InvalidArgumentException $e) {
            $this->hoursError = $e->getMessage();

            return;
        }

        // Validation above guarantees these are non-null integers
        $projectId = (int) $this->selectedProjectId;
        $taskId = (int) $this->selectedTaskId;

        /** @var User $user */
        $user = auth()->user();
        $service = app(TimeEntryService::class);

        $data = [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'spent_on' => $this->entryDate,
            'hours' => $hours,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ];

        if ($this->editingEntryId !== null) {
            $entry = $this->guardEntry($this->editingEntryId);
            if ($entry) {
                $service->update($entry, $data);
            }
        } else {
            $service->create($user, $data);
        }

        $this->closeModal();
    }

    public function deleteEntry(int $entryId): void
    {
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->delete($entry);
    }

    public function startTimer(int $entryId): void
    {
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->startTimer($entry);
    }

    public function stopTimer(int $entryId): void
    {
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->stopTimer($entry);
    }

    #[On('timerPoll')]
    public function refreshForTimer(): void
    {
        // triggered by 60s poll — Livewire re-renders automatically
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $selectedDay = CarbonImmutable::parse($this->selectedDate);
        $weekStart = $selectedDay->startOfWeek(); // Monday

        // Build week strip: Mon–Sun with daily totals
        $weekDays = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset));

        $weekEntries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->get();

        $dayTotals = $weekEntries->groupBy(fn (TimeEntry $e) => $e->spent_on->toDateString())
            ->map(fn (Collection $group) => $group->sum(fn (TimeEntry $e) => (float) $e->hours));

        $weekTotal = $weekEntries->sum(fn (TimeEntry $e) => (float) $e->hours);

        // Entries for the selected day
        $dayEntries = TimeEntry::with(['project.client', 'task'])
            ->where('user_id', $user->id)
            ->where('spent_on', $this->selectedDate)
            ->orderBy('created_at')
            ->get();

        $dayTotal = $dayEntries->sum(fn (TimeEntry $e) => (float) $e->hours);

        // Project/task picker data
        $projectsForPicker = Project::with(['client', 'tasks'])
            ->where('is_archived', false)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->orderBy('name')
            ->get();

        $selectedProject = $this->selectedProjectId
            ? $projectsForPicker->firstWhere('id', $this->selectedProjectId)
            : null;

        return view('livewire.timesheet.day-view', [
            'weekDays' => $weekDays,
            'dayTotals' => $dayTotals,
            'weekTotal' => $weekTotal,
            'dayEntries' => $dayEntries,
            'dayTotal' => $dayTotal,
            'projectsForPicker' => $projectsForPicker,
            'selectedProject' => $selectedProject,
        ]);
    }

    private function resetModal(): void
    {
        $this->editingEntryId = null;
        $this->selectedProjectId = null;
        $this->selectedTaskId = null;
        $this->projectSearch = '';
        $this->taskSearch = '';
        $this->hoursInput = '';
        $this->notes = '';
        $this->hoursError = '';
        $this->entryDate = $this->selectedDate;
    }

    private function guardEntry(int $entryId): ?TimeEntry
    {
        /** @var User $user */
        $user = auth()->user();
        $entry = TimeEntry::find($entryId);

        if (! $entry || $entry->user_id !== $user->id) {
            return null;
        }

        return $entry;
    }
}
