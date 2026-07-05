<?php

namespace App\Http\Controllers\Diagnostics;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives client-side reports that a timesheet task-picker option was clicked
 * but the selection never committed (the "dead picker" bug we could not
 * reproduce). Logged so we can catch it in the wild with real context.
 */
class PickerDiagnosticController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'kind' => 'required|string|max:64',
            'clickedId' => 'nullable|string|max:64',
            'committed' => 'nullable|string|max:64',
            'msSinceLoad' => 'nullable|integer',
            'appVersion' => 'nullable|string|max:32',
            'livewireVersion' => 'nullable|string|max:32',
            'morphCount' => 'nullable|integer',
            'url' => 'nullable|string|max:512',
        ]);

        Log::warning('picker.diagnostic', [
            ...$data,
            'user_id' => $request->user()?->id,
            'ua' => substr((string) $request->userAgent(), 0, 256),
        ]);

        return response()->noContent();
    }
}
