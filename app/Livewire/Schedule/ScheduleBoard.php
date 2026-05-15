<?php

namespace App\Livewire\Schedule;

use App\Domain\Schedule\ScheduleAvailabilityService;
use App\Domain\Schedule\ScheduleShiftService;
use App\Models\Project;
use App\Models\ScheduleAssignment;
use App\Models\SchedulePlaceholder;
use App\Models\ScheduleTimeOff;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class ScheduleBoard extends Component
{
    #[Url(as: 'view', except: 'team')]
    public string $viewMode = 'team';

    #[Url(except: 'week')]
    public string $scale = 'week';

    #[Url(as: 'date')]
    public string $selectedDate = '';

    #[Url(as: 'heatmap', except: 'availability')]
    public string $heatmapMetric = 'availability';

    #[Url(as: 'role', except: '')]
    public string $roleFilter = '';

    #[Url(as: 'team', except: '')]
    public string $teamFilter = '';

    #[Url(as: 'project', except: '')]
    public string $projectFilter = '';

    public string $scheduleFilter = 'metric:availability';

    /** @var array<string, bool> */
    public array $expandedProjects = [];

    /** @var array<string, bool> */
    public array $expandedAssignees = [];

    public bool $showAssignmentModal = false;

    public ?int $editingAssignmentId = null;

    public ?int $assignmentProjectId = null;

    public string $assignmentAssigneeType = 'user';

    public ?int $assignmentUserId = null;

    public ?int $assignmentPlaceholderId = null;

    public string $assignmentStartsOn = '';

    public string $assignmentEndsOn = '';

    public string $assignmentHoursPerDay = '7.5';

    public string $assignmentNotes = '';

    public bool $addUserToProjectTeam = true;

    public bool $showTimeOffModal = false;

    public ?int $editingTimeOffId = null;

    public ?int $timeOffUserId = null;

    public string $timeOffStartsOn = '';

    public string $timeOffEndsOn = '';

    public string $timeOffHoursPerDay = '7.5';

    public string $timeOffLabel = 'Time off';

    public string $timeOffNotes = '';

    public bool $showPlaceholderModal = false;

    public ?int $editingPlaceholderId = null;

    public string $placeholderName = '';

    public string $placeholderRoleTitle = '';

    public string $placeholderWeeklyCapacity = '40';

    /** @var array<int, int|string> */
    public array $placeholderWorkDays = [1, 2, 3, 4, 5];

    public bool $showShiftModal = false;

    public ?int $shiftProjectId = null;

    public string $shiftFromDate = '';

    public string $shiftNewStartDate = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->selectedDate)) {
            $this->selectedDate = today()->toDateString();
        }

        $this->viewMode = in_array($this->viewMode, ['projects', 'team'], true) ? $this->viewMode : 'team';
        $this->scale = in_array($this->scale, ['day', 'week', 'month'], true) ? $this->scale : 'week';
        $this->heatmapMetric = in_array($this->heatmapMetric, ['availability', 'capacity'], true) ? $this->heatmapMetric : 'availability';
        $this->teamFilter = ctype_digit($this->teamFilter) ? $this->teamFilter : '';
        $this->projectFilter = ctype_digit($this->projectFilter) ? $this->projectFilter : '';
        $this->roleFilter = trim($this->roleFilter);
        $this->syncScheduleFilterFromState();
    }

    public function setViewMode(string $viewMode): void
    {
        $this->viewMode = in_array($viewMode, ['projects', 'team'], true) ? $viewMode : 'team';
    }

    public function setScale(string $scale): void
    {
        $this->scale = in_array($scale, ['day', 'week', 'month'], true) ? $scale : 'week';
    }

    public function setHeatmapMetric(string $metric): void
    {
        $this->heatmapMetric = in_array($metric, ['availability', 'capacity'], true) ? $metric : 'availability';
        $this->syncScheduleFilterFromState();
    }

    public function updatedScheduleFilter(string $value): void
    {
        $this->applyScheduleFilter($value);
    }

    public function clearPeopleFilters(): void
    {
        $this->roleFilter = '';
        $this->teamFilter = '';
        $this->projectFilter = '';
        $this->syncScheduleFilterFromState();
    }

    public function previousPeriod(): void
    {
        $date = CarbonImmutable::parse($this->selectedDate);
        $this->selectedDate = match ($this->scale) {
            'month' => $date->subMonth()->toDateString(),
            'week' => $date->subWeeks(4)->toDateString(),
            default => $date->subWeek()->toDateString(),
        };
    }

    public function nextPeriod(): void
    {
        $date = CarbonImmutable::parse($this->selectedDate);
        $this->selectedDate = match ($this->scale) {
            'month' => $date->addMonth()->toDateString(),
            'week' => $date->addWeeks(4)->toDateString(),
            default => $date->addWeek()->toDateString(),
        };
    }

    public function goToToday(): void
    {
        $this->selectedDate = today()->toDateString();
    }

    public function selectDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->selectedDate = $date;
        }
    }

    public function toggleProject(int $projectId): void
    {
        $key = (string) $projectId;
        $this->expandedProjects[$key] = ! ($this->expandedProjects[$key] ?? false);
    }

    public function toggleAssignee(string $assigneeKey): void
    {
        $this->expandedAssignees[$assigneeKey] = ! ($this->expandedAssignees[$assigneeKey] ?? false);
    }

    public function openAssignmentModal(?int $projectId = null, ?string $assigneeType = null, ?int $assigneeId = null, ?string $startsOn = null): void
    {
        Gate::authorize('access-admin');

        $this->resetAssignmentModal();
        $this->assignmentProjectId = $projectId;
        $this->assignmentStartsOn = $startsOn ?: CarbonImmutable::parse($this->selectedDate)->startOfWeek()->toDateString();
        $this->assignmentEndsOn = CarbonImmutable::parse($this->assignmentStartsOn)->addDays(4)->toDateString();

        if ($assigneeType === 'placeholder') {
            $this->assignmentAssigneeType = 'placeholder';
            $this->assignmentPlaceholderId = $assigneeId;
        } elseif ($assigneeType === 'user') {
            $this->assignmentAssigneeType = 'user';
            $this->assignmentUserId = $assigneeId;
        }

        $this->showAssignmentModal = true;
    }

    public function editAssignment(int $assignmentId): void
    {
        Gate::authorize('access-admin');

        $assignment = ScheduleAssignment::findOrFail($assignmentId);
        $this->resetAssignmentModal();
        $this->editingAssignmentId = $assignment->id;
        $this->assignmentProjectId = $assignment->project_id;
        $this->assignmentAssigneeType = $assignment->schedule_placeholder_id !== null ? 'placeholder' : 'user';
        $this->assignmentUserId = $assignment->user_id;
        $this->assignmentPlaceholderId = $assignment->schedule_placeholder_id;
        $this->assignmentStartsOn = $assignment->starts_on->toDateString();
        $this->assignmentEndsOn = $assignment->ends_on->toDateString();
        $this->assignmentHoursPerDay = (string) $assignment->hours_per_day;
        $this->assignmentNotes = $assignment->notes ?? '';
        $this->addUserToProjectTeam = true;
        $this->showAssignmentModal = true;
    }

    public function saveAssignment(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'assignmentProjectId' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where(fn ($query) => $query->where('is_archived', false)),
            ],
            'assignmentAssigneeType' => 'required|in:user,placeholder',
            'assignmentStartsOn' => 'required|date',
            'assignmentEndsOn' => 'required|date|after_or_equal:assignmentStartsOn',
            'assignmentHoursPerDay' => 'required|numeric|min:0.25|max:24',
            'assignmentNotes' => 'nullable|string|max:1000',
        ]);

        $userId = null;
        $placeholderId = null;

        if ($this->assignmentAssigneeType === 'user') {
            $this->validate([
                'assignmentUserId' => [
                    'required',
                    'integer',
                    Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('archived_at')),
                ],
            ]);

            $userId = (int) $this->assignmentUserId;
            $project = Project::findOrFail((int) $this->assignmentProjectId);
            $alreadyOnProject = $project->users()->where('users.id', $userId)->exists();
            if (! $alreadyOnProject && ! $this->addUserToProjectTeam) {
                $this->addError('addUserToProjectTeam', 'Add this user to the project team before scheduling them.');

                return;
            }

            if (! $alreadyOnProject) {
                $project->users()->attach($userId, ['hourly_rate_override' => null, 'rate_id' => null]);
            }
        } else {
            $this->validate([
                'assignmentPlaceholderId' => [
                    'required',
                    'integer',
                    Rule::exists('schedule_placeholders', 'id')->where(fn ($query) => $query->whereNull('archived_at')),
                ],
            ]);

            $placeholderId = (int) $this->assignmentPlaceholderId;
        }

        $values = [
            'project_id' => (int) $this->assignmentProjectId,
            'user_id' => $userId,
            'schedule_placeholder_id' => $placeholderId,
            'starts_on' => $this->assignmentStartsOn,
            'ends_on' => $this->assignmentEndsOn,
            'hours_per_day' => (float) $this->assignmentHoursPerDay,
            'notes' => $this->assignmentNotes !== '' ? $this->assignmentNotes : null,
        ];

        if ($this->editingAssignmentId !== null) {
            ScheduleAssignment::findOrFail($this->editingAssignmentId)->update($values);
        } else {
            ScheduleAssignment::create($values);
        }

        $this->closeAssignmentModal();
    }

    public function closeAssignmentModal(): void
    {
        $this->showAssignmentModal = false;
        $this->resetAssignmentModal();
    }

    public function deleteAssignment(int $assignmentId): void
    {
        Gate::authorize('access-admin');

        ScheduleAssignment::findOrFail($assignmentId)->delete();
        if ($this->editingAssignmentId === $assignmentId) {
            $this->closeAssignmentModal();
        }
    }

    public function moveAssignmentToPeriod(
        int $assignmentId,
        string $periodStartsOn,
        ?string $sourcePeriodStartsOn = null,
        ?string $targetAssigneeType = null,
        ?int $targetAssigneeId = null,
    ): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'scale' => 'required|in:day,week,month',
            'selectedDate' => 'required|date',
        ]);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStartsOn)) {
            throw ValidationException::withMessages(['periodStartsOn' => 'Choose a valid target period.']);
        }

        $assignment = ScheduleAssignment::findOrFail($assignmentId);
        $targetPeriodStart = CarbonImmutable::parse($periodStartsOn);
        $targetAssignee = $this->targetAssigneeValues($assignment, $targetAssigneeType, $targetAssigneeId);

        if ($sourcePeriodStartsOn !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourcePeriodStartsOn)) {
            if ($this->moveAssignmentSegmentToPeriod($assignment, CarbonImmutable::parse($sourcePeriodStartsOn), $targetPeriodStart, $targetAssignee)) {
                return;
            }
        }

        $newStart = $targetPeriodStart;
        $durationDays = CarbonImmutable::parse($assignment->starts_on)->diffInDays(CarbonImmutable::parse($assignment->ends_on));

        $assignment->update([
            'user_id' => $targetAssignee['user_id'],
            'schedule_placeholder_id' => $targetAssignee['schedule_placeholder_id'],
            'starts_on' => $newStart->toDateString(),
            'ends_on' => $newStart->addDays((int) $durationDays)->toDateString(),
        ]);
    }

    public function openTimeOffModal(?int $userId = null, ?string $startsOn = null): void
    {
        Gate::authorize('access-admin');

        $this->resetTimeOffModal();
        $this->timeOffUserId = $userId;
        $this->timeOffStartsOn = $startsOn ?: CarbonImmutable::parse($this->selectedDate)->startOfWeek()->toDateString();
        $this->timeOffEndsOn = $this->timeOffStartsOn;
        if ($userId !== null && ($user = User::find($userId))) {
            $this->timeOffHoursPerDay = (string) app(ScheduleAvailabilityService::class)->dailyCapacity($user);
        }

        $this->showTimeOffModal = true;
    }

    public function editTimeOff(int $timeOffId): void
    {
        Gate::authorize('access-admin');

        $timeOff = ScheduleTimeOff::findOrFail($timeOffId);
        $this->resetTimeOffModal();
        $this->editingTimeOffId = $timeOff->id;
        $this->timeOffUserId = $timeOff->user_id;
        $this->timeOffStartsOn = $timeOff->starts_on->toDateString();
        $this->timeOffEndsOn = $timeOff->ends_on->toDateString();
        $this->timeOffHoursPerDay = (string) $timeOff->hours_per_day;
        $this->timeOffLabel = $timeOff->label;
        $this->timeOffNotes = $timeOff->notes ?? '';
        $this->showTimeOffModal = true;
    }

    public function saveTimeOff(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'timeOffUserId' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('archived_at')),
            ],
            'timeOffStartsOn' => 'required|date',
            'timeOffEndsOn' => 'required|date|after_or_equal:timeOffStartsOn',
            'timeOffHoursPerDay' => 'required|numeric|min:0.25|max:24',
            'timeOffLabel' => 'required|string|max:80',
            'timeOffNotes' => 'nullable|string|max:1000',
        ]);

        $values = [
            'user_id' => (int) $this->timeOffUserId,
            'starts_on' => $this->timeOffStartsOn,
            'ends_on' => $this->timeOffEndsOn,
            'hours_per_day' => (float) $this->timeOffHoursPerDay,
            'label' => $this->timeOffLabel,
            'notes' => $this->timeOffNotes !== '' ? $this->timeOffNotes : null,
        ];

        if ($this->editingTimeOffId !== null) {
            ScheduleTimeOff::findOrFail($this->editingTimeOffId)->update($values);
        } else {
            ScheduleTimeOff::create($values);
        }

        $this->closeTimeOffModal();
    }

    public function closeTimeOffModal(): void
    {
        $this->showTimeOffModal = false;
        $this->resetTimeOffModal();
    }

    public function deleteTimeOff(int $timeOffId): void
    {
        Gate::authorize('access-admin');

        ScheduleTimeOff::findOrFail($timeOffId)->delete();
        if ($this->editingTimeOffId === $timeOffId) {
            $this->closeTimeOffModal();
        }
    }

    public function openPlaceholderModal(): void
    {
        Gate::authorize('access-admin');

        $this->resetPlaceholderModal();
        $this->showPlaceholderModal = true;
    }

    public function editPlaceholder(int $placeholderId): void
    {
        Gate::authorize('access-admin');

        $placeholder = SchedulePlaceholder::findOrFail($placeholderId);
        $this->resetPlaceholderModal();
        $this->editingPlaceholderId = $placeholder->id;
        $this->placeholderName = $placeholder->name;
        $this->placeholderRoleTitle = $placeholder->role_title ?? '';
        $this->placeholderWeeklyCapacity = (string) $placeholder->weekly_capacity_hours;
        $this->placeholderWorkDays = $placeholder->effectiveScheduleWorkDays();
        $this->showPlaceholderModal = true;
    }

    public function savePlaceholder(): void
    {
        Gate::authorize('access-admin');

        $this->placeholderWorkDays = collect($this->placeholderWorkDays)
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->validate([
            'placeholderName' => 'required|string|max:255',
            'placeholderRoleTitle' => 'nullable|string|max:255',
            'placeholderWeeklyCapacity' => 'required|numeric|min:0|max:168',
            'placeholderWorkDays' => 'required|array|min:1',
        ]);

        $values = [
            'name' => $this->placeholderName,
            'role_title' => $this->placeholderRoleTitle !== '' ? $this->placeholderRoleTitle : null,
            'weekly_capacity_hours' => (float) $this->placeholderWeeklyCapacity,
            'schedule_work_days' => $this->placeholderWorkDays,
        ];

        if ($this->editingPlaceholderId !== null) {
            SchedulePlaceholder::findOrFail($this->editingPlaceholderId)->update($values);
        } else {
            SchedulePlaceholder::create($values);
        }

        $this->closePlaceholderModal();
    }

    public function closePlaceholderModal(): void
    {
        $this->showPlaceholderModal = false;
        $this->resetPlaceholderModal();
    }

    public function archivePlaceholder(int $placeholderId): void
    {
        Gate::authorize('access-admin');

        SchedulePlaceholder::findOrFail($placeholderId)->archive();
    }

    public function deletePlaceholder(int $placeholderId): void
    {
        Gate::authorize('access-admin');

        $placeholder = SchedulePlaceholder::findOrFail($placeholderId);
        if ($placeholder->scheduleAssignments()->exists()) {
            $placeholder->archive();

            return;
        }

        $placeholder->delete();
    }

    public function openShiftTimeline(int $projectId): void
    {
        Gate::authorize('access-admin');

        $project = Project::findOrFail($projectId);
        $firstFuture = $project->scheduleAssignments()
            ->whereDate('starts_on', '>=', $this->selectedDate)
            ->orderBy('starts_on')
            ->value('starts_on');

        $this->shiftProjectId = $projectId;
        $this->shiftFromDate = $firstFuture ? CarbonImmutable::parse($firstFuture)->toDateString() : CarbonImmutable::parse($this->selectedDate)->toDateString();
        $this->shiftNewStartDate = CarbonImmutable::parse($this->shiftFromDate)->addWeek()->toDateString();
        $this->showShiftModal = true;
    }

    public function closeShiftModal(): void
    {
        $this->showShiftModal = false;
        $this->shiftProjectId = null;
        $this->shiftFromDate = '';
        $this->shiftNewStartDate = '';
        $this->resetErrorBag();
    }

    public function shiftTimeline(ScheduleShiftService $shiftService): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'shiftProjectId' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where(fn ($query) => $query->where('is_archived', false)),
            ],
            'shiftFromDate' => 'required|date',
            'shiftNewStartDate' => 'required|date',
        ]);

        $project = Project::findOrFail((int) $this->shiftProjectId);
        $moved = $shiftService->shiftProject($project, $this->shiftFromDate, $this->shiftNewStartDate);
        session()->flash('schedule_status', "Shifted {$moved} assignment".($moved === 1 ? '' : 's').'.');
        $this->closeShiftModal();
    }

    public function render(ScheduleAvailabilityService $availability): View
    {
        $this->mount();
        $periods = $availability->periods($this->scale, $this->selectedDate);
        $rangeStart = $periods[0]['starts_on'];
        $rangeEnd = $periods[array_key_last($periods)]['ends_on'];

        $users = User::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->with(['manager', 'projects:id', 'teams:id'])
            ->orderBy('name')
            ->get();

        $teams = Team::query()
            ->active()
            ->orderBy('name')
            ->get();

        $placeholders = SchedulePlaceholder::query()
            ->active()
            ->orderBy('name')
            ->get();

        $projects = Project::query()
            ->with(['client', 'users'])
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        $assignments = ScheduleAssignment::query()
            ->with(['project.client', 'project.users', 'user', 'placeholder'])
            ->whereHas('project', fn ($query) => $query->where('is_archived', false))
            ->whereDate('ends_on', '>=', $rangeStart)
            ->whereDate('starts_on', '<=', $rangeEnd)
            ->orderBy('starts_on')
            ->get();

        $timeOff = ScheduleTimeOff::query()
            ->with('user')
            ->whereDate('ends_on', '>=', $rangeStart)
            ->whereDate('starts_on', '<=', $rangeEnd)
            ->orderBy('starts_on')
            ->get();

        return view('livewire.schedule.schedule-board', [
            'periods' => $periods,
            'projectRows' => $this->projectRows($projects, $assignments, $timeOff, $periods, $availability),
            'teamRows' => $this->teamRows($users, $placeholders, $assignments, $timeOff, $periods, $availability),
            'timeOffRows' => $this->timeOffRows($timeOff, $periods, $availability),
            'allProjects' => $projects,
            'allUsers' => $users,
            'allPlaceholders' => $placeholders,
            'roleOptions' => $this->roleOptions($users, $placeholders),
            'teamOptions' => $this->teamOptions($teams),
            'peopleFiltersActive' => $this->peopleFiltersActive(),
            'canEdit' => Gate::allows('access-admin'),
            'scaleLabel' => ucfirst($this->scale),
            'periodLabel' => $this->periodLabel(),
            'weekDays' => $this->weekDays(),
        ]);
    }

    /**
     * @param  array{user_id: int|null, schedule_placeholder_id: int|null}  $targetAssignee
     */
    private function moveAssignmentSegmentToPeriod(
        ScheduleAssignment $assignment,
        CarbonImmutable $sourcePeriodStart,
        CarbonImmutable $targetPeriodStart,
        array $targetAssignee,
    ): bool
    {
        $sourcePeriodEnd = $this->periodEnd($sourcePeriodStart);
        $assignmentStart = CarbonImmutable::parse($assignment->starts_on);
        $assignmentEnd = CarbonImmutable::parse($assignment->ends_on);
        $segmentStart = $this->maxDate($assignmentStart, $sourcePeriodStart);
        $segmentEnd = $this->minDate($assignmentEnd, $sourcePeriodEnd);
        $currentAssignee = $this->assignmentAssigneeValues($assignment);
        $assigneeChanges = $targetAssignee !== $currentAssignee;

        if ($segmentStart->greaterThan($segmentEnd)) {
            return false;
        }

        $targetStart = $targetPeriodStart->addDays((int) $sourcePeriodStart->diffInDays($segmentStart));
        $targetEnd = $targetStart->addDays((int) $segmentStart->diffInDays($segmentEnd));

        if (! $assigneeChanges && $targetStart->isSameDay($segmentStart) && $targetEnd->isSameDay($segmentEnd)) {
            return true;
        }

        $remainingRanges = [];
        if ($assignmentStart->lessThan($segmentStart)) {
            $remainingRanges[] = ['starts_on' => $assignmentStart, 'ends_on' => $segmentStart->subDay()];
        }

        if ($assignmentEnd->greaterThan($segmentEnd)) {
            $remainingRanges[] = ['starts_on' => $segmentEnd->addDay(), 'ends_on' => $assignmentEnd];
        }

        if ($assigneeChanges) {
            DB::transaction(function () use ($assignment, $remainingRanges, $targetStart, $targetEnd, $currentAssignee, $targetAssignee): void {
                if ($remainingRanges === []) {
                    $assignment->update([
                        ...$targetAssignee,
                        'starts_on' => $targetStart->toDateString(),
                        'ends_on' => $targetEnd->toDateString(),
                    ]);

                    return;
                }

                $firstRange = array_shift($remainingRanges);
                $assignment->update([
                    ...$currentAssignee,
                    'starts_on' => $firstRange['starts_on']->toDateString(),
                    'ends_on' => $firstRange['ends_on']->toDateString(),
                ]);

                foreach ($remainingRanges as $range) {
                    $this->createAssignmentRange($assignment, $range, $currentAssignee);
                }

                $this->createAssignmentRange($assignment, ['starts_on' => $targetStart, 'ends_on' => $targetEnd], $targetAssignee);
            });

            return true;
        }

        $ranges = $this->mergeAssignmentRanges([
            ...$remainingRanges,
            ['starts_on' => $targetStart, 'ends_on' => $targetEnd],
        ]);

        DB::transaction(function () use ($assignment, $ranges, $currentAssignee): void {
            $firstRange = array_shift($ranges);

            $assignment->update([
                ...$currentAssignee,
                'starts_on' => $firstRange['starts_on']->toDateString(),
                'ends_on' => $firstRange['ends_on']->toDateString(),
            ]);

            foreach ($ranges as $range) {
                $this->createAssignmentRange($assignment, $range, $currentAssignee);
            }
        });

        return true;
    }

    /**
     * @return array{user_id: int|null, schedule_placeholder_id: int|null}
     */
    private function targetAssigneeValues(ScheduleAssignment $assignment, ?string $targetAssigneeType, ?int $targetAssigneeId): array
    {
        if ($targetAssigneeType === null || $targetAssigneeId === null) {
            return $this->assignmentAssigneeValues($assignment);
        }

        if ($targetAssigneeType === 'user') {
            $user = User::query()
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->find($targetAssigneeId);

            if (! $user) {
                throw ValidationException::withMessages(['targetAssigneeId' => 'Choose an active user.']);
            }

            Project::findOrFail($assignment->project_id)
                ->users()
                ->syncWithoutDetaching([
                    $user->id => ['hourly_rate_override' => null, 'rate_id' => null],
                ]);

            return ['user_id' => $user->id, 'schedule_placeholder_id' => null];
        }

        if ($targetAssigneeType === 'placeholder') {
            $placeholder = SchedulePlaceholder::query()
                ->whereNull('archived_at')
                ->find($targetAssigneeId);

            if (! $placeholder) {
                throw ValidationException::withMessages(['targetAssigneeId' => 'Choose an active placeholder.']);
            }

            return ['user_id' => null, 'schedule_placeholder_id' => $placeholder->id];
        }

        throw ValidationException::withMessages(['targetAssigneeType' => 'Choose a valid assignee type.']);
    }

    /**
     * @return array{user_id: int|null, schedule_placeholder_id: int|null}
     */
    private function assignmentAssigneeValues(ScheduleAssignment $assignment): array
    {
        return [
            'user_id' => $assignment->user_id,
            'schedule_placeholder_id' => $assignment->schedule_placeholder_id,
        ];
    }

    /**
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $range
     * @param  array{user_id: int|null, schedule_placeholder_id: int|null}  $assignee
     */
    private function createAssignmentRange(ScheduleAssignment $assignment, array $range, array $assignee): void
    {
        ScheduleAssignment::create([
            'project_id' => $assignment->project_id,
            ...$assignee,
            'starts_on' => $range['starts_on']->toDateString(),
            'ends_on' => $range['ends_on']->toDateString(),
            'hours_per_day' => (float) $assignment->hours_per_day,
            'notes' => $assignment->notes,
        ]);
    }

    private function periodEnd(CarbonImmutable $periodStart): CarbonImmutable
    {
        return match ($this->scale) {
            'month' => $periodStart->endOfMonth(),
            'week' => $periodStart->addDays(6),
            default => $periodStart,
        };
    }

    /**
     * @param  array<int, array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}>  $ranges
     * @return array<int, array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}>
     */
    private function mergeAssignmentRanges(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => $a['starts_on']->getTimestamp() <=> $b['starts_on']->getTimestamp());

        return array_values(array_reduce($ranges, function (array $merged, array $range): array {
            $lastIndex = array_key_last($merged);
            if ($lastIndex === null || $range['starts_on']->greaterThan($merged[$lastIndex]['ends_on']->addDay())) {
                $merged[] = $range;

                return $merged;
            }

            $merged[$lastIndex]['ends_on'] = $this->maxDate($merged[$lastIndex]['ends_on'], $range['ends_on']);

            return $merged;
        }, []));
    }

    private function maxDate(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->greaterThan($second) ? $first : $second;
    }

    private function minDate(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->lessThan($second) ? $first : $second;
    }

    private function resetAssignmentModal(): void
    {
        $this->editingAssignmentId = null;
        $this->assignmentProjectId = null;
        $this->assignmentAssigneeType = 'user';
        $this->assignmentUserId = null;
        $this->assignmentPlaceholderId = null;
        $this->assignmentStartsOn = '';
        $this->assignmentEndsOn = '';
        $this->assignmentHoursPerDay = '7.5';
        $this->assignmentNotes = '';
        $this->addUserToProjectTeam = true;
        $this->resetErrorBag();
    }

    private function resetTimeOffModal(): void
    {
        $this->editingTimeOffId = null;
        $this->timeOffUserId = null;
        $this->timeOffStartsOn = '';
        $this->timeOffEndsOn = '';
        $this->timeOffHoursPerDay = '7.5';
        $this->timeOffLabel = 'Time off';
        $this->timeOffNotes = '';
        $this->resetErrorBag();
    }

    private function resetPlaceholderModal(): void
    {
        $this->editingPlaceholderId = null;
        $this->placeholderName = '';
        $this->placeholderRoleTitle = '';
        $this->placeholderWeeklyCapacity = '40';
        $this->placeholderWorkDays = [1, 2, 3, 4, 5];
        $this->resetErrorBag();
    }

    /**
     * @param  EloquentCollection<int, Project>  $projects
     * @param  EloquentCollection<int, ScheduleAssignment>  $assignments
     * @param  EloquentCollection<int, ScheduleTimeOff>  $timeOff
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<int, array<string, mixed>>
     */
    private function projectRows(
        EloquentCollection $projects,
        EloquentCollection $assignments,
        EloquentCollection $timeOff,
        array $periods,
        ScheduleAvailabilityService $availability,
    ): array {
        $selectedProjectId = $this->selectedProjectFilterId();

        return $projects
            ->when($selectedProjectId !== null, fn ($rows) => $rows->where('id', $selectedProjectId)->values())
            ->map(function (Project $project) use ($assignments, $periods, $availability) {
                $projectAssignments = $assignments
                    ->where('project_id', $project->id)
                    ->values();
                $assignmentRows = $projectAssignments
                    ->map(fn (ScheduleAssignment $assignment) => $this->assignmentView($assignment, $periods, $availability))
                    ->all();

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'client_name' => $project->client?->name ?? '',
                    'colour' => $this->projectColour($project->id),
                    'expanded' => $this->expandedProjects[(string) $project->id] ?? false,
                    'assignments' => $assignmentRows,
                    'scheduled_hours' => round(collect($assignmentRows)->sum('total_hours'), 2),
                    'team_count' => $project->users->count(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, User>  $users
     * @param  EloquentCollection<int, SchedulePlaceholder>  $placeholders
     * @param  EloquentCollection<int, ScheduleAssignment>  $assignments
     * @param  EloquentCollection<int, ScheduleTimeOff>  $timeOff
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<int, array<string, mixed>>
     */
    private function teamRows(
        EloquentCollection $users,
        EloquentCollection $placeholders,
        EloquentCollection $assignments,
        EloquentCollection $timeOff,
        array $periods,
        ScheduleAvailabilityService $availability,
    ): array {
        $roleFilter = mb_strtolower($this->roleFilter);
        $teamFilter = $this->selectedTeamFilterId();
        $projectFilter = $this->selectedProjectFilterId();
        $rows = collect();

        foreach ($placeholders as $placeholder) {
            $key = 'placeholder-'.$placeholder->id;
            $assigneeAssignments = $assignments
                ->where('schedule_placeholder_id', $placeholder->id)
                ->values();
            if ($roleFilter !== '' && ! str_contains(mb_strtolower($placeholder->role_title ?? ''), $roleFilter)) {
                continue;
            }

            if ($teamFilter !== null) {
                continue;
            }

            if ($projectFilter !== null && ! $assigneeAssignments->contains('project_id', $projectFilter)) {
                continue;
            }

            $visibleAssignments = $this->visibleAssignmentsForProjectFilter($assigneeAssignments, $projectFilter);
            $visibleAssignmentRows = $visibleAssignments
                ->map(fn (ScheduleAssignment $assignment) => $this->assignmentView($assignment, $periods, $availability))
                ->all();

            $rows->push([
                'key' => $key,
                'type' => 'placeholder',
                'id' => $placeholder->id,
                'name' => $placeholder->name,
                'role_title' => $placeholder->role_title,
                'is_placeholder' => true,
                'expanded' => $this->expandedAssignees[$key] ?? true,
                'metrics' => $this->periodMetrics($placeholder, $assigneeAssignments, collect(), $periods, $availability),
                'assignments' => $this->groupTeamAssignmentRows($visibleAssignmentRows),
                'time_off' => [],
            ]);
        }

        foreach ($users as $user) {
            $key = 'user-'.$user->id;
            $assigneeAssignments = $assignments
                ->where('user_id', $user->id)
                ->values();
            $assigneeTimeOff = $timeOff
                ->where('user_id', $user->id)
                ->values();
            if ($roleFilter !== '' && ! str_contains(mb_strtolower($user->role_title ?? ''), $roleFilter)) {
                continue;
            }

            if ($teamFilter !== null && ! $user->teams->contains('id', $teamFilter)) {
                continue;
            }

            if ($projectFilter !== null && ! $this->userMatchesProjectFilter($user, $assigneeAssignments, $projectFilter)) {
                continue;
            }

            $visibleAssignments = $this->visibleAssignmentsForProjectFilter($assigneeAssignments, $projectFilter);
            $visibleAssignmentRows = $visibleAssignments
                ->map(fn (ScheduleAssignment $assignment) => $this->assignmentView($assignment, $periods, $availability))
                ->all();
            $timeOffRows = $assigneeTimeOff
                ->map(fn (ScheduleTimeOff $entry) => $this->timeOffView($entry, $periods, $availability))
                ->all();

            $rows->push([
                'key' => $key,
                'type' => 'user',
                'id' => $user->id,
                'name' => $user->name,
                'role_title' => $user->role_title,
                'is_placeholder' => false,
                'expanded' => $this->expandedAssignees[$key] ?? true,
                'metrics' => $this->periodMetrics($user, $assigneeAssignments, $assigneeTimeOff, $periods, $availability),
                'assignments' => $this->groupTeamAssignmentRows($visibleAssignmentRows),
                'time_off' => $timeOffRows,
            ]);
        }

        return $rows->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignmentRows
     * @return array<int, array<string, mixed>>
     */
    private function groupTeamAssignmentRows(array $assignmentRows): array
    {
        $grouped = [];

        foreach ($assignmentRows as $assignment) {
            $key = (string) $assignment['project_id'];

            if (! isset($grouped[$key])) {
                $assignment['period_assignment_ids'] = $assignment['period_assignment_ids'] ?? [];
                $grouped[$key] = $assignment;

                continue;
            }

            foreach ($assignment['period_hours'] as $periodIndex => $hours) {
                $grouped[$key]['period_hours'][$periodIndex] = ($grouped[$key]['period_hours'][$periodIndex] ?? 0) + $hours;
                $grouped[$key]['period_assignment_ids'][$periodIndex] = $assignment['period_assignment_ids'][$periodIndex] ?? $assignment['id'];
            }

            $grouped[$key]['starts_on'] = min($grouped[$key]['starts_on'], $assignment['starts_on']);
            $grouped[$key]['ends_on'] = max($grouped[$key]['ends_on'], $assignment['ends_on']);
            $grouped[$key]['total_hours'] = round($grouped[$key]['total_hours'] + $assignment['total_hours'], 2);
        }

        return collect($grouped)
            ->sortBy('project_name')
            ->values()
            ->all();
    }

    private function selectedTeamFilterId(): ?int
    {
        return ctype_digit($this->teamFilter) ? (int) $this->teamFilter : null;
    }

    private function selectedProjectFilterId(): ?int
    {
        return ctype_digit($this->projectFilter) ? (int) $this->projectFilter : null;
    }

    private function peopleFiltersActive(): bool
    {
        return $this->roleFilter !== ''
            || $this->selectedTeamFilterId() !== null
            || $this->selectedProjectFilterId() !== null;
    }

    private function applyScheduleFilter(string $value): void
    {
        if ($value === 'metric:availability' || $value === 'metric:capacity') {
            $this->heatmapMetric = str($value)->after('metric:')->toString();
            $this->roleFilter = '';
            $this->teamFilter = '';
            $this->projectFilter = '';
            $this->scheduleFilter = $value;

            return;
        }

        if ($value === 'filter:all') {
            $this->roleFilter = '';
            $this->teamFilter = '';
            $this->projectFilter = '';
            $this->scheduleFilter = 'metric:'.$this->heatmapMetric;

            return;
        }

        $this->roleFilter = '';
        $this->teamFilter = '';
        $this->projectFilter = '';

        if (str_starts_with($value, 'role:')) {
            $this->roleFilter = trim(substr($value, 5));
            $this->scheduleFilter = $this->roleFilter !== '' ? 'role:'.$this->roleFilter : 'metric:'.$this->heatmapMetric;

            return;
        }

        if (str_starts_with($value, 'team:')) {
            $teamId = substr($value, 5);
            $this->teamFilter = ctype_digit($teamId) ? $teamId : '';
            $this->scheduleFilter = $this->teamFilter !== '' ? 'team:'.$this->teamFilter : 'metric:'.$this->heatmapMetric;

            return;
        }

        if (str_starts_with($value, 'project:')) {
            $projectId = substr($value, 8);
            $this->projectFilter = ctype_digit($projectId) ? $projectId : '';
            $this->scheduleFilter = $this->projectFilter !== '' ? 'project:'.$this->projectFilter : 'metric:'.$this->heatmapMetric;

            return;
        }

        $this->scheduleFilter = 'metric:'.$this->heatmapMetric;
    }

    private function syncScheduleFilterFromState(): void
    {
        if ($this->projectFilter !== '') {
            $this->scheduleFilter = 'project:'.$this->projectFilter;

            return;
        }

        if ($this->teamFilter !== '') {
            $this->scheduleFilter = 'team:'.$this->teamFilter;

            return;
        }

        if ($this->roleFilter !== '') {
            $this->scheduleFilter = 'role:'.$this->roleFilter;

            return;
        }

        $this->scheduleFilter = 'metric:'.$this->heatmapMetric;
    }

    /**
     * @param  EloquentCollection<int, User>  $users
     * @param  EloquentCollection<int, SchedulePlaceholder>  $placeholders
     * @return array<int, string>
     */
    private function roleOptions(EloquentCollection $users, EloquentCollection $placeholders): array
    {
        return $users
            ->pluck('role_title')
            ->merge($placeholders->pluck('role_title'))
            ->filter(fn (?string $roleTitle) => filled($roleTitle))
            ->map(fn (string $roleTitle) => trim($roleTitle))
            ->unique(fn (string $roleTitle) => mb_strtolower($roleTitle))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Team>  $teams
     * @return array<int, array{id: int, label: string}>
     */
    private function teamOptions(EloquentCollection $teams): array
    {
        return $teams
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'label' => $team->name,
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ScheduleAssignment>  $assignments
     * @return EloquentCollection<int, ScheduleAssignment>
     */
    private function visibleAssignmentsForProjectFilter(EloquentCollection $assignments, ?int $projectFilter): EloquentCollection
    {
        if ($projectFilter === null) {
            return $assignments;
        }

        return $assignments
            ->where('project_id', $projectFilter)
            ->values();
    }

    /**
     * @param  EloquentCollection<int, ScheduleAssignment>  $assignments
     */
    private function userMatchesProjectFilter(User $user, EloquentCollection $assignments, int $projectFilter): bool
    {
        return $user->projects->contains('id', $projectFilter)
            || $assignments->contains('project_id', $projectFilter);
    }

    /**
     * @param  EloquentCollection<int, ScheduleTimeOff>  $timeOff
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<int, array<string, mixed>>
     */
    private function timeOffRows(EloquentCollection $timeOff, array $periods, ScheduleAvailabilityService $availability): array
    {
        return $timeOff
            ->map(fn (ScheduleTimeOff $entry) => $this->timeOffView($entry, $periods, $availability))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function assignmentView(ScheduleAssignment $assignment, array $periods, ScheduleAvailabilityService $availability): array
    {
        $periodHours = [];
        $totalHours = 0.0;
        foreach ($periods as $period) {
            $hours = $availability->assignmentHoursForPeriod($assignment, $period['starts_on'], $period['ends_on']);
            if ($hours > 0) {
                $periodHours[$period['index']] = $hours;
                $totalHours += $hours;
            }
        }

        return [
            'id' => $assignment->id,
            'project_id' => $assignment->project_id,
            'project_name' => $assignment->project->name,
            'client_name' => $assignment->project->client?->name ?? '',
            'colour' => $this->projectColour($assignment->project_id),
            'assignee_type' => $assignment->assigneeType(),
            'assignee_id' => $assignment->assigneeId(),
            'assignee_name' => $assignment->assigneeName(),
            'assignee_role_title' => $assignment->assigneeRoleTitle(),
            'starts_on' => $assignment->starts_on->toDateString(),
            'ends_on' => $assignment->ends_on->toDateString(),
            'hours_per_day' => (float) $assignment->hours_per_day,
            'notes' => $assignment->notes,
            'period_hours' => $periodHours,
            'period_assignment_ids' => array_fill_keys(array_keys($periodHours), $assignment->id),
            'total_hours' => round($totalHours, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function timeOffView(ScheduleTimeOff $entry, array $periods, ScheduleAvailabilityService $availability): array
    {
        $periodHours = [];
        $totalHours = 0.0;
        foreach ($periods as $period) {
            $hours = $availability->timeOffHoursForPeriod($entry, $period['starts_on'], $period['ends_on']);
            if ($hours > 0) {
                $periodHours[$period['index']] = $hours;
                $totalHours += $hours;
            }
        }

        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'user_name' => $entry->user->name,
            'label' => $entry->label,
            'starts_on' => $entry->starts_on->toDateString(),
            'ends_on' => $entry->ends_on->toDateString(),
            'hours_per_day' => (float) $entry->hours_per_day,
            'notes' => $entry->notes,
            'period_hours' => $periodHours,
            'total_hours' => round($totalHours, 2),
        ];
    }

    /**
     * @param  EloquentCollection<int, ScheduleAssignment>  $assignments
     * @param  EloquentCollection<int, ScheduleTimeOff>  $timeOff
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<int, array<string, mixed>>
     */
    private function periodMetrics(
        User|SchedulePlaceholder $assignee,
        EloquentCollection $assignments,
        EloquentCollection|Collection $timeOff,
        array $periods,
        ScheduleAvailabilityService $availability,
    ): array {
        return collect($periods)
            ->map(function (array $period) use ($assignee, $assignments, $timeOff, $availability) {
                $summary = $availability->summaryForPeriod($assignee, $assignments, $timeOff, $period['starts_on'], $period['ends_on']);
                $summary['project_count'] = $assignments
                    ->filter(fn (ScheduleAssignment $assignment) => $availability->assignmentHoursForPeriod($assignment, $period['starts_on'], $period['ends_on']) > 0)
                    ->pluck('project_id')
                    ->unique()
                    ->count();
                $summary['class'] = $this->heatClass($summary);

                return $summary;
            })
            ->all();
    }

    /**
     * @param  array{capacity: float, scheduled: float, time_off: float, availability: float}  $summary
     */
    private function heatClass(array $summary): string
    {
        if ($summary['availability'] < 0) {
            return 'bg-red-100 text-red-800 border-red-200';
        }

        if ($this->heatmapMetric === 'capacity') {
            if ($summary['scheduled'] <= 0) {
                return 'bg-gray-50 text-gray-400 border-gray-100';
            }

            return $summary['scheduled'] >= $summary['capacity']
                ? 'bg-amber-100 text-amber-800 border-amber-200'
                : 'bg-blue-50 text-blue-800 border-blue-100';
        }

        if ($summary['availability'] === 0.0) {
            return 'bg-gray-100 text-gray-700 border-gray-200';
        }

        return 'bg-green-50 text-green-800 border-green-100';
    }

    private function projectColour(int $projectId): string
    {
        $palette = ['#0E8F3A', '#E65A00', '#D92D2D', '#2563EB', '#7C3AED', '#0F766E', '#B45309', '#BE123C'];

        return $palette[$projectId % count($palette)];
    }

    private function periodLabel(): string
    {
        $date = CarbonImmutable::parse($this->selectedDate);
        $rangeStart = $date->startOfWeek();
        $rangeEnd = match ($this->scale) {
            'week' => $rangeStart->addWeeks(11)->addDays(6),
            'day' => $rangeStart->addDays(41),
            default => $date->endOfYear(),
        };

        return match ($this->scale) {
            'month' => $date->format('Y'),
            'week' => $rangeStart->format('j M').' - '.$this->formatRangeEnd($rangeEnd),
            default => $rangeStart->format('j M').' - '.$this->formatRangeEnd($rangeEnd),
        };
    }

    private function formatRangeEnd(CarbonImmutable $rangeEnd): string
    {
        return $rangeEnd->year === today()->year
            ? $rangeEnd->format('j M')
            : $rangeEnd->format('j M Y');
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function weekDays(): array
    {
        return [
            ['value' => 1, 'label' => 'M'],
            ['value' => 2, 'label' => 'T'],
            ['value' => 3, 'label' => 'W'],
            ['value' => 4, 'label' => 'T'],
            ['value' => 5, 'label' => 'F'],
            ['value' => 6, 'label' => 'S'],
            ['value' => 7, 'label' => 'S'],
        ];
    }
}
