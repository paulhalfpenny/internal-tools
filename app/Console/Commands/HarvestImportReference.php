<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class HarvestImportReference extends Command
{
    protected $signature = 'harvest:import-reference
        {path : Path to the Harvest detailed-time CSV file}
        {--dry-run : Parse and validate without writing to the database}';

    protected $description = 'Import clients, projects, tasks, and project-task links from a Harvest CSV (no time entries)';

    /** @var array<string, int> */
    private array $clientCache = [];

    /** @var array<string, int> */
    private array $projectCache = [];

    /** @var array<string, int> */
    private array $taskCache = [];

    private int $clientsCreated = 0;

    private int $clientsExisting = 0;

    private int $projectsCreated = 0;

    private int $projectsExisting = 0;

    private int $tasksCreated = 0;

    private int $tasksExisting = 0;

    private int $errors = 0;

    /**
     * Keyed by projectCacheKey -> taskCacheKey -> ['is_billable', 'project_id', 'task_id'].
     * String keys are used so that dry-run new entities (id=0) don't collapse into the same slot.
     *
     * @var array<string, array<string, array{is_billable: bool, project_id: int, task_id: int}>>
     */
    private array $projectTaskLinks = [];

    /** @var list<string> Messages from entity creation, printed after the progress bar finishes. */
    private array $creationLog = [];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[dry-run] No changes will be written.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return self::FAILURE;
        }

        // Skip header row
        fgetcsv($handle);

        $bar = $this->output->createProgressBar();

        // Note: this runs outside a transaction. If the process is killed mid-import,
        // clients/projects may be partially created without their project_task links.
        $bar->start();

        while (($row = fgetcsv($handle)) !== false) {
            $bar->advance();

            // Columns used: 1=client, 2=project, 3=code, 4=task, 7=billable
            if (count($row) < 8) {
                $this->errors++;

                continue;
            }

            try {
                $this->processRow($row, $dryRun);
            } catch (\RuntimeException $e) {
                $bar->clear();
                $this->error($e->getMessage());
                $bar->display();
                $this->errors++;
            }
        }

        fclose($handle);
        $bar->finish();
        $this->newLine();

        // Print entity-creation messages collected during the loop (avoids corrupting the progress bar)
        foreach ($this->creationLog as $message) {
            $this->line($message);
        }

        // Bulk-insert project-task links.
        // Note: insertOrIgnore means existing links are never updated — re-running is safe
        // but won't correct is_billable on rows that already exist.
        if (! $dryRun) {
            $links = [];
            foreach ($this->projectTaskLinks as $tasks) {
                foreach ($tasks as $entry) {
                    // Skip rows where either ID is 0 (dry-run new entities that were never persisted)
                    if ($entry['project_id'] === 0 || $entry['task_id'] === 0) {
                        continue;
                    }
                    $links[] = [
                        'project_id' => $entry['project_id'],
                        'task_id' => $entry['task_id'],
                        'is_billable' => $entry['is_billable'],
                    ];
                }
            }

            if (! empty($links)) {
                DB::table('project_task')->insertOrIgnore($links);
            }

            $linksCreated = count($links);
        } else {
            $linksCreated = array_sum(array_map('count', $this->projectTaskLinks));
        }

        $this->info('Done.');
        $this->table(
            ['Entity', 'Created', 'Existing'],
            [
                ['Clients', $this->clientsCreated, $this->clientsExisting],
                ['Projects', $this->projectsCreated, $this->projectsExisting],
                ['Tasks', $this->tasksCreated, $this->tasksExisting],
            ]
        );
        $this->info('Project-task links'.($dryRun ? ' (would be created)' : ' created').": {$linksCreated}");

        if ($this->errors > 0) {
            $this->warn("Errors: {$this->errors}");
        }

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function processRow(array $row, bool $dryRun): void
    {
        $clientName = $row[1];
        $projectName = $row[2];
        $projectCode = $row[3];
        $taskName = $row[4];
        $billableStr = $row[7];

        $isBillable = strtolower($billableStr) === 'yes';

        $clientCacheKey = strtolower($clientName);
        $clientId = $this->resolveClient($clientName, $clientCacheKey, $dryRun);

        $projectCacheKey = strtolower($projectName).'|'.$clientCacheKey;
        $projectId = $this->resolveProject($projectName, $projectCode, $projectCacheKey, $clientId, $dryRun);

        $taskCacheKey = strtolower($taskName);
        $taskId = $this->resolveTask($taskName, $taskCacheKey, $isBillable, $dryRun);

        // Track project-task link keyed by string cache keys so dry-run new entities
        // (all assigned id=0) don't collapse into the same array slot. First occurrence wins for is_billable.
        if (! isset($this->projectTaskLinks[$projectCacheKey][$taskCacheKey])) {
            $this->projectTaskLinks[$projectCacheKey][$taskCacheKey] = [
                'is_billable' => $isBillable,
                'project_id' => $projectId,
                'task_id' => $taskId,
            ];
        }
    }

    private function resolveClient(string $name, string $key, bool $dryRun): int
    {
        if (isset($this->clientCache[$key])) {
            return $this->clientCache[$key];
        }

        $client = Client::whereRaw('LOWER(name) = ?', [$key])->first();

        if ($client === null) {
            if (! $dryRun) {
                $client = Client::create(['name' => $name]);
                $this->creationLog[] = "  Created client: {$name}";
            }
            $this->clientsCreated++;
            $this->clientCache[$key] = $dryRun ? 0 : $client->id;
        } else {
            $this->clientsExisting++;
            $this->clientCache[$key] = $client->id;
        }

        return $this->clientCache[$key];
    }

    private function resolveProject(string $name, string $code, string $key, int $clientId, bool $dryRun): int
    {
        if (isset($this->projectCache[$key])) {
            return $this->projectCache[$key];
        }

        $project = Project::whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where('client_id', $clientId)
            ->first();

        if ($project === null) {
            if (! $dryRun) {
                try {
                    $project = Project::create([
                        'client_id' => $clientId,
                        'name' => $name,
                        'code' => $code !== '' ? $code : null,
                    ]);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'projects_code_unique') || $e->getCode() === '23000') {
                        $project = Project::create([
                            'client_id' => $clientId,
                            'name' => $name,
                            'code' => null,
                        ]);
                        $this->creationLog[] = "  [code conflict] Created project without code: {$name} (code '{$code}' already in use)";
                    } else {
                        throw $e;
                    }
                }
                $this->creationLog[] = "  Created project: {$name}";
            }
            $this->projectsCreated++;
            $this->projectCache[$key] = $dryRun ? 0 : $project->id;
        } else {
            $this->projectsExisting++;
            $this->projectCache[$key] = $project->id;
        }

        return $this->projectCache[$key];
    }

    private function resolveTask(string $name, string $key, bool $isBillable, bool $dryRun): int
    {
        if (isset($this->taskCache[$key])) {
            return $this->taskCache[$key];
        }

        $task = Task::whereRaw('LOWER(name) = ?', [$key])->first();

        if ($task === null) {
            if (! $dryRun) {
                $task = Task::create([
                    'name' => $name,
                    'is_default_billable' => $isBillable,
                ]);
                $this->creationLog[] = "  Created task: {$name}";
            }
            $this->tasksCreated++;
            $this->taskCache[$key] = $dryRun ? 0 : $task->id;
        } else {
            $this->tasksExisting++;
            $this->taskCache[$key] = $task->id;
        }

        return $this->taskCache[$key];
    }
}
