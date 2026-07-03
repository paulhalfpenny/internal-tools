<?php

namespace App\Domain\TimeTracking;

use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Backs the Asana app-components endpoints: the in-task "log time" modal
 * form, its submission, and the per-task widget. See
 * docs/superpowers/specs/2026-07-03-asana-app-components-time-logging-design.md
 */
final class AsanaAppService
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly AsanaProjectAssociationService $associations,
    ) {}

    public function resolveUser(?string $asanaUserGid): ?User
    {
        if ($asanaUserGid === null || $asanaUserGid === '') {
            return null;
        }

        return User::where('asana_user_gid', $asanaUserGid)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Form shown when the acting Asana user has no linked Internal Tools
     * account. No on_submit_callback => Asana disables the submit button.
     *
     * @return array<string, mixed>
     */
    public function connectPromptForm(): array
    {
        return [
            'template' => 'form_metadata_v0',
            'metadata' => [
                'title' => 'Connect Internal Tools',
                'fields' => [
                    [
                        'type' => 'static_text',
                        'id' => 'connect_prompt',
                        'name' => 'Your Asana account is not linked to Filter Internal Tools yet. '
                            .'Open '.rtrim((string) config('app.url'), '/').'/profile/asana and click "Connect Asana", then reopen this form.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Shown when the task's board has no linked internal project the acting
     * user is assigned to (or the task has not synced yet). No
     * on_submit_callback => the submit button is disabled.
     *
     * @return array<string, mixed>
     */
    public function boardNotLinkedForm(): array
    {
        return [
            'template' => 'form_metadata_v0',
            'metadata' => [
                'title' => 'Log time to Internal Tools',
                'fields' => [
                    [
                        'type' => 'static_text',
                        'id' => 'board_not_linked',
                        'name' => 'This Asana board is not linked to an Internal Tools project you are assigned to, '
                            .'so time cannot be logged from here. An admin can link the board under '
                            .'Admin -> Projects -> edit -> Asana boards. If the board is already linked, '
                            .'this task may not have synced yet - use the Refresh button on the timesheet task picker, then reopen this form.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the log-time form for a task. $values carries the user's current
     * selections during on_change round-trips (keyed by field id).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function formMetadata(User $user, string $taskGid, array $values = [], ?string $error = null): array
    {
        $asanaTask = AsanaTask::find($taskGid);
        $boardGid = $asanaTask?->asana_project_gid ?? '';

        $projects = $this->projectsForUser($user);

        // Projects the task's board is linked to determine the project field:
        // exactly one => fixed read-only line (the normal case); several =>
        // dropdown restricted to those; none => logging is blocked (see below).
        $linkedProjects = $boardGid === ''
            ? collect()
            : $projects->filter(fn (Project $p): bool => $p->asanaProjects->contains('gid', $boardGid))->sortBy('name')->values();

        // Unsynced task or no linked project => don't offer the form at all.
        // Guessing a default project silently misattributes time, and an
        // unlinked entry can't sync back to Asana anyway.
        if ($asanaTask === null || $linkedProjects->isEmpty()) {
            return $this->boardNotLinkedForm();
        }

        $fixedProject = $linkedProjects->count() === 1 ? $linkedProjects->first() : null;
        $projectOptions = $linkedProjects;

        if ($fixedProject !== null) {
            $selectedProject = $fixedProject;
        } else {
            $selectedProjectId = $this->resolveSelectedProject($user, $projectOptions, $boardGid, $values);
            $selectedProject = $projectOptions->firstWhere('id', $selectedProjectId);
        }

        $running = $this->runningEntryForTask($user, $taskGid);
        $timerTicked = is_array($values['timer'] ?? null) && in_array('start', $values['timer'], true);

        $fields = [];

        if ($error !== null) {
            $fields[] = [
                'type' => 'static_text',
                'id' => 'form_error',
                'name' => 'Could not log time: '.$error,
            ];
        }

        // The entry is linked to the Asana task by gid regardless of what
        // the user types in Notes — surface that as a fixed, read-only line.
        $fields[] = [
            'type' => 'static_text',
            'id' => 'linked_asana_task',
            'name' => 'Asana task: '.$asanaTask->name,
        ];

        // In-form replacement for the abandoned on-task widget (attaching a
        // card would replace the Log time button — see logEntry()).
        $linkedEntries = TimeEntry::where('asana_task_gid', $taskGid)->get(['user_id', 'hours']);
        if ($linkedEntries->isNotEmpty()) {
            $format = $user->hoursDisplayFormat();
            $fields[] = [
                'type' => 'static_text',
                'id' => 'logged_so_far',
                'name' => 'Logged on this task so far: '
                    .HoursFormatter::format((float) $linkedEntries->sum('hours'), $format).' hrs total, '
                    .HoursFormatter::format((float) $linkedEntries->where('user_id', $user->id)->sum('hours'), $format).' hrs yours.',
            ];
        }

        $fields[] = $fixedProject !== null
            ? [
                'type' => 'static_text',
                'id' => 'project',
                'name' => 'Project: '.$fixedProject->timesheetDisplayName(),
            ]
            : [
                'type' => 'dropdown',
                'id' => 'project',
                'name' => 'Project',
                'is_required' => true,
                'is_watched' => true,
                'options' => $projectOptions
                    ->map(fn (Project $p): array => ['id' => (string) $p->id, 'label' => mb_substr($p->timesheetDisplayName(), 0, 80)])
                    ->values()
                    ->all(),
                'value' => isset($selectedProjectId) && $selectedProjectId !== null ? (string) $selectedProjectId : null,
                'width' => 'full',
            ];

        $fields[] = [
            'type' => 'dropdown',
            'id' => 'task',
            'name' => 'Task',
            'is_required' => true,
            'options' => $selectedProject !== null
                ? $selectedProject->tasks
                    ->where('is_archived', false)
                    ->sortBy('name')
                    ->map(fn ($t): array => ['id' => (string) $t->id, 'label' => mb_substr($t->name, 0, 80)])
                    ->values()
                    ->all()
                : [],
            'value' => $this->resolveSelectedTask($user, $selectedProject, $boardGid, $values),
            'width' => 'full',
        ];

        // Required unless the user is starting a timer (the checkbox is
        // watched, so ticking it re-renders the form with hours optional)
        // or stopping one (hours are ignored in stop mode).
        $fields[] = [
            'type' => 'single_line_text',
            'id' => 'hours',
            'name' => 'Hours',
            'is_required' => ! $timerTicked && $running === null,
            'placeholder' => '0.25 or 0:15',
            'value' => isset($values['hours']) && is_string($values['hours']) ? $values['hours'] : null,
            'width' => 'half',
        ];

        $fields[] = [
            'type' => 'date',
            'id' => 'date',
            'name' => 'Date',
            'is_required' => true,
            'value' => isset($values['date']) && is_string($values['date']) ? $values['date'] : today()->toDateString(),
            'width' => 'half',
        ];

        $fields[] = [
            'type' => 'multi_line_text',
            'id' => 'notes',
            'name' => 'Notes',
            'is_required' => false,
            'value' => isset($values['notes']) && is_string($values['notes'])
                ? $values['notes']
                : null,
        ];

        $fields[] = $running !== null
            ? [
                'type' => 'checkbox',
                'id' => 'timer',
                'name' => 'Timer',
                'is_required' => false,
                'is_watched' => true,
                'options' => [[
                    'id' => 'stop',
                    'label' => 'Stop the running timer ('.HoursFormatter::format($this->timeEntries->currentHours($running), $user->hoursDisplayFormat()).' so far)',
                ]],
            ]
            : [
                'type' => 'checkbox',
                'id' => 'timer',
                'name' => 'Timer',
                'is_required' => false,
                'is_watched' => true,
                'options' => [[
                    'id' => 'start',
                    'label' => 'Start a timer instead of logging hours',
                ]],
            ];

        return [
            'template' => 'form_metadata_v0',
            'metadata' => [
                'title' => 'Log time to Internal Tools',
                'on_submit_callback' => route('asana-app.submit'),
                'on_change_callback' => route('asana-app.form.change'),
                // Null values (e.g. an empty hours box) must be omitted, not
                // sent as JSON null — Asana's renderer rejects them.
                'fields' => array_map(
                    fn (array $field): array => array_filter($field, fn ($v) => $v !== null),
                    $fields,
                ),
            ],
        ];
    }

    /**
     * Handle a form submission. Returns Asana's expected on_submit payload:
     * a resource to attach on success (which makes the widget appear), or
     * an error message.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function submit(User $user, string $taskGid, array $values): array
    {
        $timerChoices = is_array($values['timer'] ?? null) ? $values['timer'] : [];

        try {
            if (in_array('stop', $timerChoices, true)) {
                return $this->stopTimer($user, $taskGid);
            }

            return $this->logEntry($user, $taskGid, $values, startTimer: in_array('start', $timerChoices, true));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Could not log time.';

            return $this->formMetadata($user, $taskGid, $values, error: (string) $message);
        } catch (AuthorizationException $e) {
            return $this->formMetadata($user, $taskGid, $values, error: $e->getMessage());
        }
    }

    /**
     * A terminal form (no submit button) confirming what just happened —
     * Asana's on_submit contract offers no attachment-free 200, so success
     * is conveyed by re-rendering the form via the 400 channel.
     *
     * @return array<string, mixed>
     */
    private function confirmationForm(string $message): array
    {
        return [
            'template' => 'form_metadata_v0',
            'metadata' => [
                'title' => 'Time logged',
                'fields' => [
                    [
                        'type' => 'static_text',
                        'id' => 'confirmation',
                        'name' => $message.' You can close this window.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Widget payload for a task, cached briefly — Asana requests it on every
     * task open. $user may be null (viewer without a linked account).
     *
     * @return array<string, mixed>
     */
    public function widget(?User $user, string $taskGid): array
    {
        // Raw aggregates are cached once per task (not per viewer) so a single
        // Cache::forget on submit refreshes the widget for everyone at once.
        // Viewer-specific bits (own hours, HH:MM preference) are applied after
        // the cache read.
        $stats = Cache::remember('asana_app_widget_'.$taskGid, 60, function () use ($taskGid): array {
            $entries = TimeEntry::with('user')
                ->where('asana_task_gid', $taskGid)
                ->get();

            $running = $entries->firstWhere('is_running', true);

            return [
                'total' => (float) $entries->sum('hours'),
                'per_user' => $entries->groupBy('user_id')
                    ->map(fn ($group): float => (float) $group->sum('hours'))
                    ->all(),
                'count' => $entries->count(),
                'latest_at' => $entries->sortByDesc('created_at')->first()?->created_at?->toIso8601String(),
                'running_user_name' => $running?->user->name,
            ];
        });

        $format = $user?->hoursDisplayFormat() ?? HoursFormatter::FORMAT_DECIMAL;
        $own = $user !== null ? ($stats['per_user'][$user->id] ?? null) : null;

        $fields = [
            [
                'name' => 'Total logged',
                'type' => 'text_with_icon',
                'text' => HoursFormatter::format($stats['total'], $format).' hrs',
            ],
        ];

        if ($user !== null) {
            $fields[] = [
                'name' => 'Your time',
                'type' => 'text_with_icon',
                'text' => HoursFormatter::format($own ?? 0.0, $format).' hrs',
            ];
        }

        $fields[] = [
            'name' => 'Entries',
            'type' => 'pill',
            'text' => (string) $stats['count'],
            'color' => 'none',
        ];

        if ($stats['latest_at'] !== null) {
            $fields[] = [
                'name' => 'Last entry',
                'type' => 'datetime_with_icon',
                'datetime' => $stats['latest_at'],
            ];
        }

        if ($stats['running_user_name'] !== null) {
            $fields[] = [
                'name' => 'Timer',
                'type' => 'pill',
                'text' => 'Running — '.$stats['running_user_name'],
                'color' => 'green',
            ];
        }

        return [
            'template' => 'summary_with_details_v0',
            'metadata' => [
                'title' => 'Time logged',
                'fields' => array_slice($fields, 0, 5),
                'footer' => [
                    'footer_type' => 'custom_text',
                    'text' => 'Filter Internal Tools',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function logEntry(User $user, string $taskGid, array $values, bool $startTimer): array
    {
        $projectId = (int) ($values['project'] ?? 0);
        $taskId = (int) ($values['task'] ?? 0);

        // Single-mapped boards render the project as a fixed line, so the
        // submit payload carries no project value — resolve it the same way.
        if ($projectId <= 0) {
            $projectId = $this->fixedProjectFor($user, AsanaTask::find($taskGid)?->asana_project_gid)?->id ?? 0;
        }

        if ($projectId <= 0 || $taskId <= 0) {
            return $this->formMetadata($user, $taskGid, $values, error: 'Pick a project and task first.');
        }

        $hoursInput = is_string($values['hours'] ?? null) ? trim($values['hours']) : '';
        if (! $startTimer && $hoursInput === '') {
            return $this->formMetadata($user, $taskGid, $values, error: 'Enter the hours to log, or tick "Start a timer instead".');
        }

        $hours = 0.0;
        if ($hoursInput !== '') {
            try {
                $hours = HoursParser::parse($hoursInput);
            } catch (\InvalidArgumentException $e) {
                return $this->formMetadata($user, $taskGid, $values, error: $e->getMessage());
            }
        }

        $dateInput = is_string($values['date'] ?? null) ? trim($values['date']) : '';
        $date = today()->toDateString();
        if ($dateInput !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateInput);
            if ($parsed === false || $parsed->format('Y-m-d') !== $dateInput) {
                return $this->formMetadata($user, $taskGid, $values, error: 'Enter the date as YYYY-MM-DD.');
            }
            $date = $dateInput;
        }

        // Only link the Asana task when the chosen project is actually linked
        // to this task's board — otherwise log without the gid (subject to the
        // project's own asana_task_required rule).
        $asanaTask = AsanaTask::find($taskGid);
        $boardGid = $asanaTask?->asana_project_gid;
        $projectIsLinked = $boardGid !== null
            && Project::whereKey($projectId)->whereHas('asanaProjects', fn ($q) => $q->where('gid', $boardGid))->exists();
        $gidToStore = $projectIsLinked ? $taskGid : null;

        ProjectTaskUsability::ensure($user, $projectId, $taskId, $gidToStore);

        $notes = is_string($values['notes'] ?? null) && trim($values['notes']) !== '' ? trim($values['notes']) : null;

        $entry = $this->timeEntries->create($user, [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'spent_on' => $date,
            'hours' => $hours,
            'notes' => $notes,
            'asana_task_gid' => $gidToStore,
        ]);

        if ($startTimer) {
            $this->timeEntries->startTimer($entry);
        }

        if ($boardGid !== null) {
            $this->associations->remember($user, $boardGid, $projectId, $taskId);
        }

        Cache::forget('asana_app_widget_'.$taskGid);

        // Never attach a resource card: an attached card replaces the app's
        // "Log time" entry point on the task, which kills repeat logging.
        // Totals are shown inside the form (logged_so_far) instead.
        $summary = $startTimer
            ? 'Timer started on '.$entry->project->timesheetDisplayName().' — '.$entry->task->name.'.'
            : 'Logged '.HoursFormatter::format($hours, $user->hoursDisplayFormat()).' hrs to '
                .$entry->project->timesheetDisplayName().' — '.$entry->task->name.'.';

        return $this->confirmationForm($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function stopTimer(User $user, string $taskGid): array
    {
        $entry = $this->runningEntryForTask($user, $taskGid);
        if ($entry === null) {
            return $this->formMetadata($user, $taskGid, [], error: 'No running timer on this task.');
        }

        $this->timeEntries->stopTimer($entry);
        Cache::forget('asana_app_widget_'.$taskGid);

        $entry->refresh();

        return $this->confirmationForm(
            'Timer stopped — logged '.HoursFormatter::format((float) $entry->hours, $user->hoursDisplayFormat()).' hrs.'
        );
    }

    private function runningEntryForTask(User $user, string $taskGid): ?TimeEntry
    {
        return TimeEntry::where('user_id', $user->id)
            ->where('asana_task_gid', $taskGid)
            ->where('is_running', true)
            ->first();
    }

    /**
     * @return Collection<int, Project>
     */
    private function projectsForUser(User $user)
    {
        return Project::with(['client', 'tasks' => fn ($q) => $q->where('tasks.is_archived', false), 'asanaProjects'])
            ->where('is_archived', false)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Pick the preselected project among the offered dropdown options:
     * explicit choice, then remembered association, then first option.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $options
     * @param  array<string, mixed>  $values
     */
    private function resolveSelectedProject(User $user, $options, string $boardGid, array $values): ?int
    {
        // Explicit user choice (on_change round trip) wins.
        $chosen = $values['project'] ?? null;
        if (is_string($chosen) && $chosen !== '' && $options->contains('id', (int) $chosen)) {
            return (int) $chosen;
        }

        $remembered = $boardGid !== '' ? $this->associations->lookup($user, $boardGid) : null;
        if ($remembered !== null && $options->contains('id', $remembered['project_id'])) {
            return $remembered['project_id'];
        }

        // Always default to something: with no selected project the task
        // dropdown would render with zero options, which Asana's form
        // renderer rejects outright ("Something went wrong").
        return $options->sortBy('name')->first()?->id;
    }

    /**
     * The single internal project a task's board maps to for this user, when
     * unambiguous — mirrors the form's fixed-project mode so a submit without
     * a project value resolves identically.
     */
    private function fixedProjectFor(User $user, ?string $boardGid): ?Project
    {
        if ($boardGid === null || $boardGid === '') {
            return null;
        }

        $linked = $this->projectsForUser($user)
            ->filter(fn (Project $p): bool => $p->asanaProjects->contains('gid', $boardGid));

        return $linked->count() === 1 ? $linked->first() : null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function resolveSelectedTask(User $user, ?Project $selectedProject, string $boardGid, array $values): ?string
    {
        if ($selectedProject === null) {
            return null;
        }

        $chosen = $values['task'] ?? null;
        if (is_string($chosen) && $chosen !== '' && $selectedProject->tasks->contains('id', (int) $chosen)) {
            return $chosen;
        }

        $remembered = $boardGid !== '' ? $this->associations->lookup($user, $boardGid) : null;
        if ($remembered !== null
            && $remembered['project_id'] === $selectedProject->id
            && $selectedProject->tasks->contains('id', $remembered['task_id'])) {
            return (string) $remembered['task_id'];
        }

        return null;
    }
}
