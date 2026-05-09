<?php

namespace Database\Seeders;

use App\Models\CalendarEventAssociation;
use App\Models\Client;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds demo data exercising the new feedback-round features:
 * rate library, per-client default tasks, calendar event associations,
 * and a project manager assignment for budget alerts.
 *
 * Idempotent: re-running upserts.
 */
class PhaseFeedbackDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Rate library
        $std = Rate::firstOrCreate(['name' => 'Standard Dev'], ['hourly_rate' => 85.00]);
        $sr = Rate::firstOrCreate(['name' => 'Senior Strategy'], ['hourly_rate' => 150.00]);
        Rate::firstOrCreate(['name' => 'Junior Support'], ['hourly_rate' => 50.00]);

        // 2. Default tasks for JDW: pre-attach Development + PM to every new JDW project
        $jdw = Client::where('name', 'JDW Projects')->first();
        if ($jdw) {
            $devTask = Task::where('name', 'Development')->first();
            $pmTask = Task::where('name', 'Project Management, Meetings & Reporting')->first();
            if ($devTask && $pmTask) {
                $jdw->defaultTasks()->syncWithoutDetaching([
                    $devTask->id => ['sort_order' => 0],
                    $pmTask->id => ['sort_order' => 1],
                ]);
            }
        }

        // 3. Calendar event associations for the admin
        $admin = User::where('email', config('app.admin_email', env('ADMIN_EMAIL')))->first();
        $devProject = Project::where('code', 'AAB-WEB-01')->first();
        $devTask = Task::where('name', 'Development')->first();
        if ($admin && $devProject && $devTask) {
            CalendarEventAssociation::updateOrCreate(
                ['user_id' => $admin->id, 'event_title' => 'Daily standup'],
                ['project_id' => $devProject->id, 'task_id' => $devTask->id, 'last_used_at' => now()]
            );
        }

        // 4. Assign Sarah Chen as the manager on the over-budget CI project so she
        //    gets budget alerts (in addition to admins).
        $sarah = User::where('email', 'sarah@filter.agency')->first();
        $ciOver = Project::where('code', 'ABC-CI-02')->first();
        if ($sarah && $ciOver) {
            $ciOver->update(['manager_user_id' => $sarah->id]);
        }

        // 5. Hook one existing project up to a library rate so the matrix has
        //    something to show.
        if ($devProject && $std) {
            $devProject->update(['rate_id' => $std->id]);
        }

        // 6. Pre-existing entries for "Daily standup" notes so the timesheet shows
        //    the calendar-pull association working end-to-end.
        if ($admin && $devProject && $devTask) {
            TimeEntry::firstOrCreate(
                [
                    'user_id' => $admin->id,
                    'project_id' => $devProject->id,
                    'task_id' => $devTask->id,
                    'spent_on' => now()->toDateString(),
                    'notes' => 'Daily standup',
                ],
                [
                    'hours' => 0.25,
                    'is_running' => false,
                    'is_billable' => true,
                    'billable_rate_snapshot' => 85.00,
                    'billable_amount' => 21.25,
                ]
            );
        }

        $this->command?->info('Seeded feedback-round demo data: 3 library rates, JDW default tasks, 1 calendar association, 1 project manager.');
    }
}
