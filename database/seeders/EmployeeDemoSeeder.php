<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds 3 non-admin employees plus some time entries on the demo projects
 * so admins can practise impersonating from /admin/timesheets.
 *
 * Idempotent: re-running clears only these employees' entries first.
 */
class EmployeeDemoSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            ['name' => 'Sarah Chen', 'email' => 'sarah@filter.agency', 'rate' => 90],
            ['name' => 'Marcus Patel', 'email' => 'marcus@filter.agency', 'rate' => 75],
            ['name' => 'Lena Brooks', 'email' => 'lena@filter.agency', 'rate' => 110],
        ];

        $projects = Project::whereNotNull('budget_type')->get();
        $devTask = Task::where('name', 'Development')->firstOrFail();
        $pmTask = Task::where('name', 'Project Management, Meetings & Reporting')->firstOrFail();

        $now = CarbonImmutable::now();
        $weekStart = $now->startOfWeek();

        foreach ($employees as $i => $data) {
            $user = User::firstOrNew(['email' => $data['email']]);
            $user->name = $data['name'];
            $user->role = Role::User;
            $user->default_hourly_rate = $data['rate'];
            $user->is_active = true;
            $user->save();

            // Attach to all budgeted demo projects.
            foreach ($projects as $project) {
                $project->users()->syncWithoutDetaching([$user->id => ['hourly_rate_override' => null]]);
            }

            // Wipe just this employee's existing entries so re-runs are clean.
            TimeEntry::where('user_id', $user->id)->delete();

            // Sprinkle entries across this week (Mon–Fri so far).
            $maxDay = min(5, $now->dayOfWeekIso); // 1=Mon..5=Fri
            for ($day = 0; $day < $maxDay; $day++) {
                $date = $weekStart->addDays($day);
                $project = $projects[($i + $day) % max(1, $projects->count())] ?? null;
                if (! $project) {
                    continue;
                }
                $task = $day % 2 === 0 ? $devTask : $pmTask;
                $hours = round(2 + ($i * 0.5) + ($day % 3), 2);
                $rate = $data['rate'];

                TimeEntry::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'task_id' => $task->id,
                    'spent_on' => $date->toDateString(),
                    'hours' => $hours,
                    'is_running' => false,
                    'is_billable' => true,
                    'billable_rate_snapshot' => $rate,
                    'billable_amount' => round($hours * $rate, 2),
                    'invoiced_at' => null,
                ]);
            }
        }

        $this->command?->info('Seeded '.count($employees).' employees with time entries.');
    }
}
