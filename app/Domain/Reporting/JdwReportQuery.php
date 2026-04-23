<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class JdwReportQuery
{
    /**
     * Programme Management block columns in JDW report order.
     * These are the canonical JDW column names (not necessarily the DB task names).
     */
    public const PROGRAMME_TASKS = [
        'Planning',
        'Project Management, Meetings & Reporting',
        'Design',
        'Admin',
        'Systems Admin',
        'Research',
        'Training',
        'Finance',
        'HR',
        'Recruitment',
        'Travel',
        'Break',
        'Lunch',
        'Holiday',
        'Bank Holiday',
        'Sick',
        'Other Absence',
    ];

    /** Projects block columns in JDW report order. */
    public const PROJECTS_TASKS = [
        'Development',
        'Project Management, Meetings & Reporting',
        'Testing',
        'Planning',
        'Systems Admin',
        'Design',
        'Release',
    ];

    /** Support & Maintenance block columns in JDW report order. */
    public const SM_TASKS = [
        'Customer Support',
        'Project Management, Meetings & Reporting',
        'Maintenance',
        'Systems Admin',
    ];

    /**
     * Maps Harvest-imported task names to their canonical JDW column names.
     * Harvest exports use slightly different task names from the JDW report columns.
     */
    public const TASK_ALIASES = [
        'Project Management, Meetings, Reporting' => 'Project Management, Meetings & Reporting',
        'Meeting' => 'Project Management, Meetings & Reporting',
        'Customer support' => 'Customer Support',
    ];

    private readonly CarbonImmutable $start;

    private readonly CarbonImmutable $end;

    public function __construct(CarbonImmutable $month)
    {
        $this->start = $month->startOfMonth();
        $this->end = $month->endOfMonth();
    }

    /**
     * Returns task_name => hours|null for the Programme Management block.
     * Null means zero hours — renders as empty cell (not "0.00").
     *
     * @return array<string, float|null>
     */
    public function programmeRow(): array
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table('time_entries')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->select('tasks.name as task_name', DB::raw('SUM(time_entries.hours) as total_hours'))
            ->where('projects.jdw_category', 'programme')
            ->whereBetween('time_entries.spent_on', [$this->start->toDateString(), $this->end->toDateString()])
            ->groupBy('tasks.name')
            ->get();

        $byTask = $this->indexByCanonicalTask($rows);

        $result = [];
        foreach (self::PROGRAMME_TASKS as $task) {
            $h = isset($byTask[$task]) ? round((float) $byTask[$task], 2) : null;
            $result[$task] = ($h !== null && $h > 0.0) ? $h : null;
        }

        return $result;
    }

    /**
     * Returns projects with jdw_category='project', each with per-task hours.
     *
     * @return Collection<int, array{id: int, name: string, code: string|null, jdw_sort_order: int|null, jdw_status: string|null, jdw_estimated_launch: string|null, jdw_description: string|null, hours: array<string, float|null>}>
     */
    public function projectsRows(): Collection
    {
        return $this->blockRows('project', self::PROJECTS_TASKS);
    }

    /**
     * Returns projects with jdw_category='support_maintenance', each with per-task hours.
     *
     * @return Collection<int, array{id: int, name: string, code: string|null, jdw_sort_order: int|null, jdw_status: string|null, jdw_estimated_launch: string|null, jdw_description: string|null, hours: array<string, float|null>}>
     */
    public function smRows(): Collection
    {
        return $this->blockRows('support_maintenance', self::SM_TASKS);
    }

    /**
     * @param  string[]  $tasks
     * @return Collection<int, array{id: int, name: string, code: string|null, jdw_sort_order: int|null, jdw_status: string|null, jdw_estimated_launch: string|null, jdw_description: string|null, hours: array<string, float|null>}>
     */
    private function blockRows(string $category, array $tasks): Collection
    {
        $projects = DB::table('projects')
            ->where('jdw_category', $category)
            ->orderBy('jdw_sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'jdw_sort_order', 'jdw_status', 'jdw_estimated_launch', 'jdw_description']);

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->pluck('id')->all();

        /** @var Collection<string, Collection<int, \stdClass>> $hoursRows */
        $hoursRows = DB::table('time_entries')
            ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->select('time_entries.project_id', 'tasks.name as task_name', DB::raw('SUM(time_entries.hours) as total_hours'))
            ->whereIn('time_entries.project_id', $projectIds)
            ->whereBetween('time_entries.spent_on', [$this->start->toDateString(), $this->end->toDateString()])
            ->groupBy('time_entries.project_id', 'tasks.name')
            ->get()
            ->groupBy('project_id');

        return $projects->map(function (object $project) use ($hoursRows, $tasks): array {
            $projectHours = $hoursRows->get((string) $project->id, collect());
            $byTask = $this->indexByCanonicalTask($projectHours);

            $hours = [];
            foreach ($tasks as $task) {
                $h = isset($byTask[$task]) ? round((float) $byTask[$task], 2) : null;
                $hours[$task] = ($h !== null && $h > 0.0) ? $h : null;
            }

            return [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'code' => $project->code !== null ? (string) $project->code : null,
                'jdw_sort_order' => $project->jdw_sort_order !== null ? (int) $project->jdw_sort_order : null,
                'jdw_status' => $project->jdw_status !== null ? (string) $project->jdw_status : null,
                'jdw_estimated_launch' => $project->jdw_estimated_launch !== null ? (string) $project->jdw_estimated_launch : null,
                'jdw_description' => $project->jdw_description !== null ? (string) $project->jdw_description : null,
                'hours' => $hours,
            ];
        });
    }

    /**
     * Re-index a collection of {task_name, total_hours} rows by their canonical JDW column name,
     * applying TASK_ALIASES so that Harvest-specific task names are normalised.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, float>
     */
    private function indexByCanonicalTask(Collection $rows): array
    {
        $byTask = [];

        foreach ($rows as $row) {
            /** @var object{task_name: string, total_hours: string|float} $row */
            $name = (string) $row->task_name;
            $canonical = self::TASK_ALIASES[$name] ?? $name;
            $hours = (float) $row->total_hours;
            $byTask[$canonical] = ($byTask[$canonical] ?? 0.0) + $hours;
        }

        return $byTask;
    }
}
