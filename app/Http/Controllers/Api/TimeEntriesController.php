<?php

namespace App\Http\Controllers\Api;

use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeEntriesController
{
    public function store(Request $request, TimeEntryService $service): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'task_id' => 'required|integer|exists:tasks,id',
            'spent_on' => 'required|date_format:Y-m-d',
            'hours' => 'required|string',
            'notes' => 'nullable|string|max:2000',
            'asana_task_gid' => 'nullable|string',
        ]);

        $user = $request->user();

        // Project access: user must be assigned to the project.
        $project = Project::with(['users', 'tasks'])->findOrFail($data['project_id']);
        if (! $project->users->contains('id', $user->id)) {
            return response()->json(['error' => 'project_not_assigned'], 403);
        }

        // Task must be on the project.
        if (! $project->tasks->contains('id', $data['task_id'])) {
            return response()->json(['error' => 'task_not_on_project'], 422);
        }

        // Asana enforcement matches DayView: if project is linked, an Asana
        // task gid is required and must belong to the project.
        if ($project->asana_project_gid !== null) {
            $gid = $data['asana_task_gid'] ?? null;
            if ($gid === null || $gid === '') {
                return response()->json(['error' => 'asana_task_required'], 422);
            }
            $valid = AsanaTask::where('gid', $gid)
                ->where('asana_project_gid', $project->asana_project_gid)
                ->exists();
            if (! $valid) {
                return response()->json(['error' => 'asana_task_invalid'], 422);
            }
        }

        try {
            $hours = HoursParser::parse($data['hours']);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'invalid_hours'], 422);
        }

        $entry = $service->create($user, [
            'project_id' => $data['project_id'],
            'task_id' => $data['task_id'],
            'spent_on' => $data['spent_on'],
            'hours' => $hours,
            'notes' => $data['notes'] ?? null,
            'asana_task_gid' => $data['asana_task_gid'] ?? null,
        ]);

        return response()->json([
            'id' => $entry->id,
            'spent_on' => $entry->spent_on->toDateString(),
            'hours' => (float) $entry->hours,
            'is_billable' => (bool) $entry->is_billable,
        ], 201);
    }
}
