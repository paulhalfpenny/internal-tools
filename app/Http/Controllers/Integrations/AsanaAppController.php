<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Landing point for time logging initiated from Asana: legacy widget-card
 * attachments and the browser extension's toolbar button both open
 * /asana-app/tasks/{gid}, which deep-links into the timesheet with the
 * entry modal opened and prefilled for that task (see
 * DayView::openModalForAsanaTask()). Session-authenticated like any page.
 *
 * The app-components endpoints (form/submit/widget) were removed in favour
 * of the extension — see docs/superpowers/specs/
 * 2026-07-03-asana-browser-extension-design.md for the platform
 * constraints that drove this.
 */
class AsanaAppController extends Controller
{
    public function show(string $taskGid): RedirectResponse
    {
        return redirect()->route('timesheet', ['log_asana' => $taskGid]);
    }
}
