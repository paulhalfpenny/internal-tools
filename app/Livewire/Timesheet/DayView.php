<?php

namespace App\Livewire\Timesheet;

use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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

    public ?int $selectedProjectId = null;

    public ?int $selectedTaskId = null;

    public string $selectedAsanaTaskGid = '';

    // Entry form fields
    public string $hoursInput = '';

    public string $notes = '';

    public string $entryDate = '';

    public string $hoursError = '';

    // Calendar panel state
    public bool $showCalendarPanel = false;

    /** @var array<int, array{id: string, title: string, start_formatted: string, end_formatted: string, hours: float}> */
    public array $calendarEvents = [];

    public bool $calendarLoading = false;

    public ?string $calendarError = null;

    // Admin impersonation: when set, the admin is editing this user's timesheet.
    public ?int $viewedUserId = null;

    public bool $isImpersonating = false;

    private ?User $viewedUserCache = null;

    public function mount(?User $user = null): void
    {
        $this->selectedDate = Carbon::today()->toDateString();
        $this->entryDate = $this->selectedDate;

        if ($user !== null && $user->exists) {
            abort_unless(Gate::allows('access-admin'), 403);
            if ($user->id !== auth()->id()) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
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

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
    }

    public function previousWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->addWeek()->toDateString();
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
        $this->selectedAsanaTaskGid = $entry->asana_task_gid ?? '';
        $this->hoursInput = (string) $entry->hours;
        $this->notes = $entry->notes ?? '';
        $this->entryDate = $entry->spent_on->toDateString();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showCalendarPanel = false;
        $this->calendarEvents = [];
        $this->calendarError = null;
        $this->resetModal();
    }

    public function startTimerFromModal(): void
    {
        if ($this->isImpersonating) {
            return;
        }

        $this->hoursError = '';

        $this->validate([
            'selectedProjectId' => 'required|integer',
            'selectedTaskId' => 'required|integer',
            'entryDate' => 'required|date',
        ]);

        if (! $this->validateAsanaTaskRequirement()) {
            return;
        }

        $hours = 0.0;
        if ($this->hoursInput !== '' && $this->hoursInput !== '0:00') {
            try {
                $hours = HoursParser::parse($this->hoursInput);
            } catch (InvalidArgumentException $e) {
                $this->hoursError = $e->getMessage();

                return;
            }
        }

        $user = $this->viewedUser();
        $service = app(TimeEntryService::class);

        $entry = $service->create($user, [
            'project_id' => (int) $this->selectedProjectId,
            'task_id' => (int) $this->selectedTaskId,
            'spent_on' => $this->entryDate,
            'hours' => $hours,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'asana_task_gid' => $this->selectedAsanaTaskGid !== '' ? $this->selectedAsanaTaskGid : null,
        ]);

        $service->startTimer($entry);
        $this->closeModal();
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

        if (! $this->validateAsanaTaskRequirement()) {
            return;
        }

        try {
            $hours = HoursParser::parse($this->hoursInput);
        } catch (InvalidArgumentException $e) {
            $this->hoursError = $e->getMessage();

            return;
        }

        // Validation above guarantees these are non-null integers
        $projectId = (int) $this->selectedProjectId;
        $taskId = (int) $this->selectedTaskId;

        $user = $this->viewedUser();
        $service = app(TimeEntryService::class);

        $data = [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'spent_on' => $this->entryDate,
            'hours' => $hours,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'asana_task_gid' => $this->selectedAsanaTaskGid !== '' ? $this->selectedAsanaTaskGid : null,
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

    private function validateAsanaTaskRequirement(): bool
    {
        if ($this->selectedProjectId === null) {
            return true;
        }

        $project = Project::find($this->selectedProjectId);
        if ($project === null || ! $project->asanaLinked()) {
            return true;
        }

        if (! $this->asanaIntegrationAvailable()) {
            $this->addError(
                'selectedAsanaTaskGid',
                'Asana integration is not active. An admin needs to connect Asana before time can be logged on linked projects.'
            );

            return false;
        }

        if ($this->selectedAsanaTaskGid === '') {
            $this->addError('selectedAsanaTaskGid', 'Pick the Asana task this time relates to.');

            return false;
        }

        $exists = AsanaTask::where('gid', $this->selectedAsanaTaskGid)
            ->where('asana_project_gid', $project->asana_project_gid)
            ->exists();

        if (! $exists) {
            $this->addError('selectedAsanaTaskGid', 'That Asana task is no longer in this project. Refresh tasks and try again.');

            return false;
        }

        return true;
    }

    private function asanaIntegrationAvailable(): bool
    {
        return User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->where('is_active', true)
            ->exists();
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
        if ($this->isImpersonating) {
            return;
        }

        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->startTimer($entry);
    }

    public function stopTimer(int $entryId): void
    {
        if ($this->isImpersonating) {
            return;
        }

        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->stopTimer($entry);
    }

    public function openCalendarPanel(): void
    {
        if ($this->isImpersonating) {
            return;
        }

        $this->showCalendarPanel = true;
        $this->calendarError = null;
        $this->calendarLoading = false;

        /** @var User $user */
        $user = auth()->user();
        $service = app(CalendarService::class);

        if (! $service->hasToken($user)) {
            $this->calendarError = 'no_token';

            return;
        }

        $events = $service->getTodayEvents($user);

        if ($events === []) {
            $this->calendarError = 'empty';

            return;
        }

        $this->calendarEvents = $events;
    }

    public function closeCalendarPanel(): void
    {
        $this->showCalendarPanel = false;
        $this->calendarEvents = [];
        $this->calendarError = null;
    }

    public function pullFromCalendarEvent(string $title, float $hours): void
    {
        if ($this->isImpersonating) {
            return;
        }

        $this->notes = $title;
        $this->hoursInput = $this->formatHoursAsTime($hours);
        $this->showCalendarPanel = false;
    }

    private function formatHoursAsTime(float $hours): string
    {
        $h = (int) $hours;
        $m = (int) round(($hours - $h) * 60);

        return $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    #[On('timerPoll')]
    public function refreshForTimer(): void
    {
        // triggered by 60s poll — Livewire re-renders automatically
    }

    public function render(): View
    {
        $user = $this->viewedUser();

        $selectedDay = CarbonImmutable::parse($this->selectedDate);
        $weekStart = $selectedDay->startOfWeek(); // Monday

        // Build week strip: Mon–Sun with daily totals
        $weekDays = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset));

        $weekEntries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->select(['spent_on', 'hours'])
            ->get();

        $dayTotals = $weekEntries->groupBy(fn (TimeEntry $e) => $e->spent_on->toDateString())
            ->map(fn (Collection $group) => $group->sum(fn (TimeEntry $e) => (float) $e->hours));

        $weekTotal = $weekEntries->sum(fn (TimeEntry $e) => (float) $e->hours);

        // Entries for the selected day
        $dayEntries = TimeEntry::with(['project.client', 'task', 'asanaTask'])
            ->where('user_id', $user->id)
            ->where('spent_on', $this->selectedDate)
            ->orderBy('created_at')
            ->get();

        $dayTotal = $dayEntries->sum(fn (TimeEntry $e) => (float) $e->hours);

        $projectsForPicker = Cache::remember(
            "projects_picker_{$user->id}",
            now()->addMinutes(10),
            fn () => Project::with(['client', 'tasks'])
                ->where('is_archived', false)
                ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('name')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'client_name' => $p->client->name,
                    'asana_project_gid' => $p->asana_project_gid,
                    'tasks' => $p->tasks->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'colour' => $t->colour,
                        'is_billable' => (bool) $t->pivot->getAttribute('is_billable'),
                    ])->values()->all(),
                ])
                ->values()
                ->all()
        );

        $linkedAsanaProjectGids = collect($projectsForPicker)
            ->pluck('asana_project_gid')
            ->filter()
            ->unique()
            ->values()
            ->all();

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

        // Track which calendar event titles are already logged today
        $usedEventTitles = $dayEntries->pluck('notes')->filter()->map(fn ($n) => strtolower($n))->all();

        return view('livewire.timesheet.day-view', [
            'weekDays' => $weekDays,
            'dayTotals' => $dayTotals,
            'weekTotal' => $weekTotal,
            'dayEntries' => $dayEntries,
            'dayTotal' => $dayTotal,
            'projectsForPicker' => $projectsForPicker,
            'asanaTasksByProject' => $asanaTasksByProject,
            'asanaAvailable' => $this->asanaIntegrationAvailable(),
            'usedEventTitles' => $usedEventTitles,
            'emptySong' => null,
            'viewedUser' => $user,
        ]);
    }

    private function resetModal(): void
    {
        $this->editingEntryId = null;
        $this->selectedProjectId = null;
        $this->selectedTaskId = null;
        $this->selectedAsanaTaskGid = '';
        $this->hoursInput = '';
        $this->notes = '';
        $this->hoursError = '';
        $this->entryDate = $this->selectedDate;
        $this->resetErrorBag();
    }

    private function guardEntry(int $entryId): ?TimeEntry
    {
        $user = $this->viewedUser();
        $entry = TimeEntry::find($entryId);

        if (! $entry || $entry->user_id !== $user->id) {
            return null;
        }

        return $entry;
    }
}
