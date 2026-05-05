<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Seeder;

class HarvestTimeReportSeeder extends Seeder
{
    /** @var array<string,bool> */
    private array $usedCodes = [];

    private function uniqueCode(?string $csvCode, string $projectName): string
    {
        $words = preg_split('/[\s\-_:&]+/', strtoupper($projectName), -1, PREG_SPLIT_NO_EMPTY);
        $initials = substr(implode('', array_map(fn ($w) => substr($w, 0, 1), $words)), 0, 8);
        $fallback = preg_replace('/[^A-Z0-9]/', '', $initials) ?: 'PRJ';

        $base = $csvCode ?: $fallback;

        $candidate = $base;
        $i = 2;
        while (isset($this->usedCodes[$candidate]) || Project::where('code', $candidate)->exists()) {
            $candidate = $base.$i;
            $i++;
        }

        $this->usedCodes[$candidate] = true;

        return $candidate;
    }

    public function run(): void
    {
        $path = base_path('sourcefiles/exports/harvest_time_report.csv');

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->command->error("Cannot open {$path}");

            return;
        }

        // Read header row
        $header = fgetcsv($handle);
        $clientIdx = array_search('Client', $header);
        $projectIdx = array_search('Project', $header);
        $codeIdx = array_search('Project Code', $header);
        $taskIdx = array_search('Task', $header);
        $billableIdx = array_search('Billable?', $header);

        // Collect unique combinations
        $projects = []; // ['ClientName' => ['ProjectName' => 'Code', ...]]
        $tasks = [];    // ['TaskName' => bool (billable)]

        while (($row = fgetcsv($handle)) !== false) {
            $client = trim($row[$clientIdx] ?? '');
            $project = trim($row[$projectIdx] ?? '');
            $code = trim($row[$codeIdx] ?? '');
            $task = trim($row[$taskIdx] ?? '');
            $billable = strtolower(trim($row[$billableIdx] ?? '')) === 'yes';

            if ($client !== '' && $project !== '') {
                if (! isset($projects[$client][$project])) {
                    $projects[$client][$project] = $code;
                }
            }

            if ($task !== '' && ! isset($tasks[$task])) {
                $tasks[$task] = $billable;
            }
        }

        fclose($handle);

        $this->command->info('Unique clients: '.count($projects));
        $this->command->info('Unique projects: '.array_sum(array_map('count', $projects)));
        $this->command->info('Unique tasks: '.count($tasks));

        // Colours pool for new tasks
        $colours = [
            '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
            '#06B6D4', '#84CC16', '#F97316', '#EC4899', '#6366F1',
            '#14B8A6', '#A855F7', '#EAB308', '#22C55E', '#0EA5E9',
            '#D946EF', '#FB923C', '#64748B', '#78716C', '#6B7280',
            '#1D4ED8', '#065F46', '#BE123C', '#0369A1', '#15803D',
            '#7C3AED', '#B45309', '#0F766E', '#9D174D', '#4338CA',
        ];

        // 1. Upsert tasks
        $maxOrder = Task::max('sort_order') ?? -1;
        $order = $maxOrder + 1;
        $taskModels = [];

        foreach ($tasks as $name => $billable) {
            $task = Task::firstOrCreate(['name' => $name], [
                'is_default_billable' => $billable,
                'colour' => $colours[$order % count($colours)],
                'sort_order' => $order,
                'is_archived' => false,
            ]);
            $taskModels[$name] = $task;
            $order++;
        }

        // 2. Upsert clients + projects, then attach all tasks to each project
        foreach ($projects as $clientName => $projectMap) {
            $client = Client::firstOrCreate(['name' => $clientName]);

            foreach ($projectMap as $projectName => $projectCode) {
                $code = $this->uniqueCode($projectCode ?: null, $projectName);

                $project = Project::firstOrCreate(
                    ['client_id' => $client->id, 'name' => $projectName],
                    [
                        'code' => $code,
                        'is_archived' => false,
                    ]
                );

                // Attach any tasks not yet linked to this project
                $existingTaskIds = $project->tasks()->pluck('tasks.id')->toArray();
                $toAttach = [];
                foreach ($taskModels as $task) {
                    if (! in_array($task->id, $existingTaskIds, true)) {
                        $toAttach[$task->id] = ['is_billable' => $task->is_default_billable];
                    }
                }
                if (! empty($toAttach)) {
                    $project->tasks()->attach($toAttach);
                }
            }
        }

        $this->command->info('Import complete.');
    }
}
