<?php

namespace App\Domain\TimeTracking;

use App\Models\CalendarEventAssociation;
use App\Models\User;

final class CalendarEventAssociationService
{
    /**
     * Look up the project/task previously associated with this calendar event title for this user.
     *
     * @return array{project_id: int, task_id: int}|null
     */
    public function lookup(User $user, string $title): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $assoc = CalendarEventAssociation::query()
            ->where('user_id', $user->id)
            ->where('event_title', $title)
            ->first();

        if ($assoc === null) {
            return null;
        }

        return [
            'project_id' => $assoc->project_id,
            'task_id' => $assoc->task_id,
        ];
    }

    /**
     * Remember (or update) the project/task associated with this calendar event title for this user.
     */
    public function remember(User $user, string $title, int $projectId, int $taskId): void
    {
        $title = trim($title);
        if ($title === '') {
            return;
        }

        CalendarEventAssociation::updateOrCreate(
            ['user_id' => $user->id, 'event_title' => $title],
            [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'last_used_at' => now(),
            ]
        );
    }
}
