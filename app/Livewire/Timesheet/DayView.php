<?php

namespace App\Livewire\Timesheet;

use App\Domain\TimeTracking\CalendarEventAssociationService;
use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class DayView extends Component
{
    #[Url(as: 'date')]
    public string $selectedDate = '';

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

    // Calendar panel state. Events themselves are prefetched per-week in
    // render() and passed to the view; only the panel toggle and the
    // 'no_token' error live as Livewire state.
    public bool $showCalendarPanel = false;

    public ?string $calendarError = null;

    // Title of the calendar event the user pulled in for the current modal session.
    // When set, save() will upsert a CalendarEventAssociation so the same event auto-fills next time.
    public ?string $lastCalendarPullTitle = null;

    // Admin impersonation: when set, the admin is editing this user's timesheet.
    #[Locked]
    public ?int $viewedUserId = null;

    // True when an admin is editing another user's timesheet. Some actions
    // (calendar pull, timer) are blocked because they need the target's
    // Google token; create/edit/delete are still allowed.
    #[Locked]
    public bool $isImpersonating = false;

    // True when a manager is viewing a direct report's timesheet. Strictly
    // read-only: every write action returns early and the UI hides edit
    // affordances. Locked so a tampered wire payload can't flip it to false.
    #[Locked]
    public bool $isReadOnly = false;

    // Where the "← back" link in the impersonation/read-only banner should
    // point. Set at mount() based on which route the user entered through:
    // /admin/timesheets/* → admin index; /team/* (or anywhere else) → my
    // timesheet. Locked so it can't be tampered with.
    #[Locked]
    public string $backUrl = '';

    #[Locked]
    public string $backLabel = '';

    private ?User $viewedUserCache = null;

    public function mount(?User $user = null): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->selectedDate)) {
            $this->selectedDate = Carbon::today()->toDateString();
        }
        $this->entryDate = $this->selectedDate;

        if ($user !== null && $user->exists && $user->id !== auth()->id()) {
            /** @var User $authUser */
            $authUser = auth()->user();
            $cameFromAdmin = (bool) (request()->route()?->getName() === 'admin.timesheets.user');

            if ($authUser->isAdmin() && $cameFromAdmin) {
                // Admin via /admin/timesheets/{user} — full impersonation.
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('admin.timesheets');
                $this->backLabel = 'Back to admin index';
            } elseif ($authUser->isAdmin() && $user->reports_to_user_id === $authUser->id) {
                // Admin who is also this user's manager, arriving via /team/{user}
                // — let them edit (admin can always edit), but route the back
                // link to their own timesheet because that's where they came
                // from.
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($user->reports_to_user_id === $authUser->id) {
                // Manager viewing a direct report — read-only.
                $this->viewedUserId = $user->id;
                $this->isReadOnly = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($authUser->isAdmin()) {
                // Admin viewing someone who isn't their report, but didn't come
                // through the admin route (e.g. crafted URL). Still allow edit
                // but back to their own timesheet.
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
        if ($this->isReadOnly) {
            return;
        }

        $this->resetModal();
        $this->entryDate = $this->selectedDate;
        $this->showModal = true;

        // Auto-open the calendar panel by default when starting a new entry.
        // The events themselves are already prefetched & cached in render(),
        // so this is just a UI toggle — no extra API hit.
        if (! $this->isImpersonating && app(CalendarService::class)->hasToken($this->viewedUser())) {
            $this->showCalendarPanel = true;
            $this->calendarError = null;
        }
    }

    public function openEditModal(int $entryId): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        $this->resetModal();
        $this->editingEntryId = $entryId;
        $this->selectedProjectId = $entry->project_id;
        $this->selectedTaskId = $entry->task_id;
        $this->selectedAsanaTaskGid = $entry->asana_task_gid ?? '';
        $this->hoursInput = HoursFormatter::asTime((float) $entry->hours);
        $this->notes = $entry->notes ?? '';
        $this->entryDate = $entry->spent_on->toDateString();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showCalendarPanel = false;
        $this->resetModal();
    }

    public function startTimerFromModal(): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
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
        if ($this->isReadOnly) {
            return;
        }
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

        $isEdit = $this->editingEntryId !== null;
        if ($isEdit && $this->editingEntryId !== null) {
            $entry = $this->guardEntry($this->editingEntryId);
            if ($entry) {
                $service->update($entry, $data);
            }
        } else {
            $service->create($user, $data);
        }

        if ($this->lastCalendarPullTitle !== null) {
            app(CalendarEventAssociationService::class)
                ->remember($user, $this->lastCalendarPullTitle, $projectId, $taskId);
        }

        if ($isEdit) {
            // Editing: close as before.
            $this->closeModal();

            return;
        }

        // Quick-add: clear the form but keep the modal + calendar panel open
        // so the admin can immediately log the next entry. The day's entry
        // list re-renders to show what was just saved, and the calendar
        // sidebar greys out the just-used event.
        $entryDate = $this->entryDate;
        $this->resetModal();
        $this->entryDate = $entryDate;
        $this->showModal = true;
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

        $taskGidProvided = $this->selectedAsanaTaskGid !== '';
        $required = (bool) $project->asana_task_required;
        $fromCalendar = $this->lastCalendarPullTitle !== null;

        // Accept with no Asana task when either the project allows it, or the
        // entry was pulled from a calendar invite (meetings like standups get
        // logged against the project code directly).
        if ((! $required || $fromCalendar) && ! $taskGidProvided) {
            return true;
        }

        if (! $this->asanaIntegrationAvailable()) {
            $this->addError(
                'selectedAsanaTaskGid',
                'Asana integration is not active. An admin needs to connect Asana before time can be logged on linked projects.'
            );

            return false;
        }

        if ($required && ! $taskGidProvided) {
            $this->addError('selectedAsanaTaskGid', 'Pick the Asana task this time relates to.');

            return false;
        }

        $linkedBoardGids = $project->asanaProjects()->pluck('gid')->all();
        $exists = AsanaTask::where('gid', $this->selectedAsanaTaskGid)
            ->whereIn('asana_project_gid', $linkedBoardGids)
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

    public function copyRowsFromMostRecent(): void
    {
        if ($this->isReadOnly) {
            return;
        }

        $user = $this->viewedUser();

        $alreadyHasEntries = TimeEntry::where('user_id', $user->id)
            ->whereDate('spent_on', $this->selectedDate)
            ->exists();
        if ($alreadyHasEntries) {
            return;
        }

        $mostRecentPrior = TimeEntry::where('user_id', $user->id)
            ->where('spent_on', '<', $this->selectedDate)
            ->orderByDesc('spent_on')
            ->value('spent_on');

        if (! $mostRecentPrior) {
            return;
        }

        $sourceEntries = TimeEntry::with(['project.tasks', 'project.users', 'project.asanaProjects'])
            ->where('user_id', $user->id)
            ->whereDate('spent_on', $mostRecentPrior)
            ->orderBy('created_at')
            ->get();

        $service = app(TimeEntryService::class);
        $copied = 0;

        foreach ($sourceEntries as $source) {
            $project = $source->project;
            if ($project->is_archived) {
                continue;
            }
            if (! $project->users->contains('id', $user->id)) {
                continue;
            }
            if (! $project->tasks->contains('id', $source->task_id)) {
                continue;
            }

            $asanaGid = $source->asana_task_gid;
            if ($project->asanaLinked()) {
                $linkedBoardGids = $project->asanaProjects->pluck('gid')->all();

                if ($asanaGid !== null) {
                    $stillValid = AsanaTask::where('gid', $asanaGid)
                        ->whereIn('asana_project_gid', $linkedBoardGids)
                        ->exists();
                    if (! $stillValid) {
                        $asanaGid = null;
                    }
                }

                if ($asanaGid === null && (bool) $project->asana_task_required) {
                    continue;
                }
            } else {
                $asanaGid = null;
            }

            $service->create($user, [
                'project_id' => $source->project_id,
                'task_id' => $source->task_id,
                'spent_on' => $this->selectedDate,
                'hours' => 0,
                'notes' => $source->notes,
                'asana_task_gid' => $asanaGid,
            ]);
            $copied++;
        }

        $sourceDateLabel = Carbon::parse($mostRecentPrior)->format('l, j F');
        if ($copied > 0) {
            session()->flash(
                'copy_rows_message',
                'Copied '.$copied.' row'.($copied === 1 ? '' : 's').' from '.$sourceDateLabel.'.'
            );
        } else {
            session()->flash('copy_rows_message', 'No rows could be copied from '.$sourceDateLabel.'.');
        }
    }

    public function deleteEntry(int $entryId): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->delete($entry);
    }

    public function startTimer(int $entryId): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
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
        if ($this->isImpersonating || $this->isReadOnly) {
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
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $this->showCalendarPanel = true;
        $this->calendarError = null;

        // Events themselves are prefetched in render(); this just toggles the
        // panel open. The error state for missing tokens is also computed in
        // render() so it stays correct after page refreshes.
    }

    public function closeCalendarPanel(): void
    {
        $this->showCalendarPanel = false;
    }

    public function pullFromCalendarEvent(string $title, float $hours): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $this->notes = $title;
        $this->hoursInput = HoursFormatter::asTime($hours);
        $this->showCalendarPanel = false;
        $this->lastCalendarPullTitle = $title;

        // Auto-fill project/task from a previously remembered association for this event title.
        $assoc = app(CalendarEventAssociationService::class)->lookup($this->viewedUser(), $title);
        if ($assoc !== null) {
            $this->selectedProjectId = $assoc['project_id'];
            $this->selectedTaskId = $assoc['task_id'];
        }
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

        // Entries for the selected day. whereDate() instead of where() to keep
        // matching when spent_on is stored as a full datetime (MySQL DATE columns
        // truncate to date-only, SQLite stores whatever string Eloquent writes).
        $dayEntries = TimeEntry::with(['project.client', 'task', 'asanaTask'])
            ->where('user_id', $user->id)
            ->whereDate('spent_on', $this->selectedDate)
            ->orderBy('created_at')
            ->get();

        $dayTotal = $dayEntries->sum(fn (TimeEntry $e) => (float) $e->hours);

        /** @var array<int, array{id: int, name: string, client_name: string, asana_project_gids: array<int, string>, asana_task_required: bool, tasks: array<int, array{id: int, name: string, colour: string, is_billable: bool}>}> $projectsForPicker */
        $projectsForPicker = Cache::remember(
            "projects_picker_{$user->id}",
            now()->addMinutes(10),
            fn () => Project::with(['client', 'tasks', 'asanaProjects'])
                ->where('is_archived', false)
                ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('name')
                ->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'client_name' => $p->client->name,
                    'asana_project_gids' => $p->asanaProjects->pluck('gid')->values()->all(),
                    'asana_task_required' => (bool) $p->asana_task_required,
                    'tasks' => $p->tasks->map(function (Task $t) {
                        /** @var Pivot $pivot */
                        $pivot = $t->getRelation('pivot');

                        return [
                            'id' => $t->id,
                            'name' => $t->name,
                            'colour' => $t->colour,
                            'is_billable' => (bool) $pivot->getAttribute('is_billable'),
                        ];
                    })->values()->all(),
                ])
                ->values()
                ->all()
        );

        $linkedAsanaProjectGids = collect($projectsForPicker)
            ->flatMap(fn ($p) => $p['asana_project_gids'])
            ->unique()
            ->values()
            ->all();

        $asanaProjectNames = \App\Models\AsanaProject::query()
            ->whereIn('gid', $linkedAsanaProjectGids)
            ->pluck('name', 'gid');

        $asanaTasksByProject = AsanaTask::query()
            ->whereIn('asana_project_gid', $linkedAsanaProjectGids)
            ->where('is_completed', false)
            ->orderBy('name')
            ->get(['gid', 'asana_project_gid', 'name'])
            ->groupBy('asana_project_gid')
            ->map(fn ($group) => $group->map(fn (AsanaTask $t) => [
                'gid' => $t->gid,
                'name' => $t->name,
                'board_name' => $asanaProjectNames[$t->asana_project_gid] ?? null,
            ])->values()->all())
            ->all();

        // Track which calendar event titles are already logged today
        $usedEventTitles = $dayEntries->pluck('notes')->filter()->map(fn ($n) => strtolower($n))->all();

        // Prefetch the whole week's calendar events into a 5-min cache. Cache is
        // keyed on (user, weekStart) so sibling days within the same week reuse
        // it without re-hitting Google. Entire-week fetch is one API call per
        // accessible calendar (10x cheaper than per-day).
        $calendarEvents = [];
        $calendarHasToken = false;
        if (! $this->isImpersonating) {
            $calService = app(CalendarService::class);
            $calendarHasToken = $calService->hasToken($user);
            if ($calendarHasToken) {
                $weekEvents = Cache::remember(
                    "calendar_events_{$user->id}_{$weekStart->toDateString()}",
                    now()->addMinutes(5),
                    fn () => $calService->getEventsForDateRange(
                        $user,
                        Carbon::parse($weekStart),
                        Carbon::parse($weekStart->addDays(6)),
                    ),
                );
                $calendarEvents = $weekEvents[$this->selectedDate] ?? [];
            }
        }
        // Keep the Livewire-state error in sync with what render() decides, so
        // it stays correct across re-renders (and after the modal closes/reopens).
        $this->calendarError = ($calendarHasToken || $this->isImpersonating) ? null : 'no_token';

        // Direct reports of the *currently authenticated* user (not the viewed
        // user, which differs during impersonation/team-view). Powers the
        // 'Team Timesheets' dropdown in the header. We keep it empty when
        // viewing someone else's sheet to avoid stacking dropdowns on a sheet
        // that's already secondary.
        $teamMembers = collect();
        $authUser = auth()->user();
        if ($authUser !== null && $this->viewedUserId === null) {
            $teamMembers = $authUser->directReports()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $canCopyFromPrior = ! $this->isReadOnly
            && $dayEntries->isEmpty()
            && TimeEntry::where('user_id', $user->id)
                ->where('spent_on', '<', $this->selectedDate)
                ->exists();

        return view('livewire.timesheet.day-view', [
            'weekDays' => $weekDays,
            'dayTotals' => $dayTotals,
            'weekTotal' => $weekTotal,
            'dayEntries' => $dayEntries,
            'dayTotal' => $dayTotal,
            'canCopyFromPrior' => $canCopyFromPrior,
            'projectsForPicker' => $projectsForPicker,
            'asanaTasksByProject' => $asanaTasksByProject,
            'asanaAvailable' => $this->asanaIntegrationAvailable(),
            'usedEventTitles' => $usedEventTitles,
            'calendarEvents' => $calendarEvents,
            'teamMembers' => $teamMembers,
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
        $this->lastCalendarPullTitle = null;
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
