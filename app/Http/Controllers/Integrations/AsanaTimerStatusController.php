<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;

/**
 * Polled by the browser extension's service worker so the toolbar icon can
 * show a running-timer cue. Deliberately outside the auth middleware: a
 * logged-out user gets a plain {running: false} instead of a redirect the
 * extension would have to special-case.
 */
class AsanaTimerStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $user = auth()->user();
        if ($user === null) {
            return response()->json(['running' => false]);
        }

        $entry = TimeEntry::where('user_id', $user->id)
            ->where('is_running', true)
            ->first();

        return response()->json([
            'running' => $entry !== null,
            'gid' => $entry?->asana_task_gid,
        ]);
    }
}
