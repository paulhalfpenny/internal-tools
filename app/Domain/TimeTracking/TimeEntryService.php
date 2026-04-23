<?php

namespace App\Domain\TimeTracking;

use App\Domain\Billing\RateResolver;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

final class TimeEntryService
{
    public function __construct(private readonly RateResolver $rateResolver) {}

    /**
     * @param  array{project_id: int, task_id: int, spent_on: string, hours: float, notes: string|null}  $data
     */
    public function create(User $user, array $data): TimeEntry
    {
        $project = Project::with(['tasks', 'users'])->findOrFail($data['project_id']);
        $task = Task::findOrFail($data['task_id']);

        $resolution = $this->rateResolver->resolveWithHours($project, $task, $user, $data['hours']);

        return TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => $data['spent_on'],
            'hours' => $data['hours'],
            'notes' => $data['notes'] ?? null,
            'is_running' => false,
            'is_billable' => $resolution->isBillable,
            'billable_rate_snapshot' => $resolution->rateSnapshot,
            'billable_amount' => $resolution->billableAmount,
        ]);
    }

    /**
     * @param  array{project_id?: int, task_id?: int, spent_on?: string, hours?: float, notes?: string|null}  $data
     */
    public function update(TimeEntry $entry, array $data): TimeEntry
    {
        $projectId = $data['project_id'] ?? $entry->project_id;
        $taskId = $data['task_id'] ?? $entry->task_id;
        $hours = $data['hours'] ?? (float) $entry->hours;

        $project = Project::with(['tasks', 'users'])->findOrFail($projectId);
        $task = Task::findOrFail($taskId);
        $user = $entry->user;

        $resolution = $this->rateResolver->resolveWithHours($project, $task, $user, $hours);

        $entry->update([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => $data['spent_on'] ?? $entry->spent_on->toDateString(),
            'hours' => $hours,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $entry->notes,
            'is_billable' => $resolution->isBillable,
            'billable_rate_snapshot' => $resolution->rateSnapshot,
            'billable_amount' => $resolution->billableAmount,
        ]);

        $entry->refresh();

        return $entry;
    }

    public function delete(TimeEntry $entry): void
    {
        if ($entry->is_running) {
            $entry->update(['is_running' => false, 'timer_started_at' => null]);
        }
        $entry->delete();
    }

    public function startTimer(TimeEntry $entry): void
    {
        // Stop any other running timer for this user first
        TimeEntry::where('user_id', $entry->user_id)
            ->where('is_running', true)
            ->where('id', '!=', $entry->id)
            ->each(fn (TimeEntry $running) => $this->stopTimer($running));

        $entry->update([
            'is_running' => true,
            'timer_started_at' => Carbon::now(),
            'spent_on' => $entry->spent_on ?? Carbon::today()->toDateString(),
        ]);
    }

    public function stopTimer(TimeEntry $entry): void
    {
        if (! $entry->is_running || $entry->timer_started_at === null) {
            return;
        }

        $elapsed = Carbon::now()->diffInSeconds($entry->timer_started_at) / 3600;
        $newHours = round((float) $entry->hours + $elapsed, 2);
        $newHours = max(0.01, min(24.0, $newHours));

        /** @var Project $project */
        $project = $entry->project()->with(['tasks', 'users'])->firstOrFail();
        $task = $entry->task;
        $user = $entry->user;

        $resolution = $this->rateResolver->resolveWithHours($project, $task, $user, $newHours);

        $entry->update([
            'hours' => $newHours,
            'is_running' => false,
            'timer_started_at' => null,
            'is_billable' => $resolution->isBillable,
            'billable_rate_snapshot' => $resolution->rateSnapshot,
            'billable_amount' => $resolution->billableAmount,
        ]);
    }
}
