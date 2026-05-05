<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ~60 users × 24 months of realistic time entries for report performance testing.
 * Generates roughly 60,000–80,000 rows.
 */
class ReportPerformanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding performance dataset (60 users × 24 months)…');

        // Create clients and projects
        $clients = Client::factory(12)->create();
        $tasks = Task::all();

        if ($tasks->isEmpty()) {
            $this->call(TaskSeeder::class);
            $tasks = Task::all();
        }

        $projects = collect();
        foreach ($clients as $client) {
            $clientProjects = Project::factory(rand(3, 8))->create([
                'client_id' => $client->id,
                'is_billable' => true,
            ]);
            foreach ($clientProjects as $project) {
                $project->tasks()->attach(
                    $tasks->random(rand(3, 8))->pluck('id')->toArray(),
                    fn ($id) => ['is_billable' => true, 'hourly_rate_override' => null]
                );
            }
            $projects = $projects->concat($clientProjects);
        }

        // Create 60 users
        $users = User::factory(60)->create();

        // Assign each user to a random subset of projects
        foreach ($users as $user) {
            $assignedProjects = $projects->random(rand(2, 8));
            foreach ($assignedProjects as $project) {
                DB::table('project_user')->insertOrIgnore([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'hourly_rate_override' => null,
                ]);
            }
        }

        // Generate 24 months of entries
        $end = Carbon::today()->startOfMonth();
        $start = $end->copy()->subMonths(23)->startOfMonth();

        $rows = [];
        $batchSize = 500;

        $current = $start->copy();
        while ($current->lessThanOrEqualTo($end)) {
            // Only weekdays
            if ($current->isWeekend()) {
                $current->addDay();

                continue;
            }

            foreach ($users->random(rand(40, 60)) as $user) {
                // 1–3 entries per active day
                $entriesPerDay = rand(1, 3);

                // Pick random projects assigned to this user
                $assigned = DB::table('project_user')
                    ->where('user_id', $user->id)
                    ->pluck('project_id');

                if ($assigned->isEmpty()) {
                    continue;
                }

                for ($i = 0; $i < $entriesPerDay; $i++) {
                    $projectId = $assigned->random();
                    $project = $projects->firstWhere('id', $projectId);

                    if (! $project) {
                        continue;
                    }

                    $taskIds = DB::table('project_task')
                        ->where('project_id', $projectId)
                        ->pluck('task_id');

                    if ($taskIds->isEmpty()) {
                        continue;
                    }

                    $taskId = $taskIds->random();
                    $hours = round(rand(50, 400) / 100, 2); // 0.5–4.0 hours
                    $rate = 84.0;
                    $isBillable = (bool) $project->is_billable;

                    $rows[] = [
                        'user_id' => $user->id,
                        'project_id' => $projectId,
                        'task_id' => $taskId,
                        'spent_on' => $current->toDateString(),
                        'hours' => $hours,
                        'notes' => null,
                        'is_running' => false,
                        'timer_started_at' => null,
                        'is_billable' => $isBillable,
                        'billable_rate_snapshot' => $isBillable ? $rate : null,
                        'billable_amount' => $isBillable ? round($hours * $rate, 2) : 0.00,
                        'invoiced_at' => null,
                        'external_reference' => null,
                        'created_at' => $current->toDateTimeString(),
                        'updated_at' => $current->toDateTimeString(),
                    ];

                    if (count($rows) >= $batchSize) {
                        DB::table('time_entries')->insert($rows);
                        $rows = [];
                    }
                }
            }

            $current->addDay();
        }

        if (! empty($rows)) {
            DB::table('time_entries')->insert($rows);
        }

        $count = DB::table('time_entries')->count();
        $this->command->info("Done. {$count} time entries total.");
    }
}
