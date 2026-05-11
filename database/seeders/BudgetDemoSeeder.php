<?php

namespace Database\Seeders;

use App\Enums\BudgetType;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds three budgeted projects with realistic time entries so the
 * Budget vs Actuals report has something to render.
 *
 * Idempotent: re-running clears only the demo projects' time entries
 * before re-seeding.
 */
class BudgetDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first() ?? User::factory()->create([
            'email' => 'demo@filteragency.com',
            'name' => 'Demo User',
            'default_hourly_rate' => 100,
        ]);

        $devTask = Task::where('name', 'Development')->firstOrFail();
        $pmTask = Task::where('name', 'Project Management, Meetings & Reporting')->firstOrFail();

        $aab = Client::where('name', 'AAB')->firstOrFail();
        $abc = Client::where('name', 'Agile Business Consortium')->firstOrFail();
        $filter = Client::where('name', 'Filter Agency')->firstOrFail();

        $now = CarbonImmutable::now();
        $threeMonthsAgo = $now->subMonths(3)->startOfMonth();

        // 1. Fixed-fee project, 60% burned
        $fixedFee = $this->upsertProject([
            'client_id' => $aab->id,
            'code' => 'AAB-WEB-01',
            'name' => 'Website Rebuild',
            'is_billable' => true,
            'default_hourly_rate' => 100,
            'starts_on' => $threeMonthsAgo->toDateString(),
            'budget_type' => BudgetType::FixedFee,
            'budget_amount' => 20000,
            'budget_hours' => 200,
        ]);

        // 2. CI Retainer — healthy (slightly under budget overall)
        $ciHealthy = $this->upsertProject([
            'client_id' => $abc->id,
            'code' => 'ABC-CI-01',
            'name' => 'Monthly Care Retainer',
            'is_billable' => true,
            'default_hourly_rate' => 100,
            'starts_on' => $threeMonthsAgo->toDateString(),
            'budget_type' => BudgetType::MonthlyCi,
            'budget_amount' => 1500,
            'budget_hours' => 15,
            'budget_starts_on' => $threeMonthsAgo->toDateString(),
        ]);

        // 3. CI Retainer — over budget
        $ciOver = $this->upsertProject([
            'client_id' => $abc->id,
            'code' => 'ABC-CI-02',
            'name' => 'Growth Retainer',
            'is_billable' => true,
            'default_hourly_rate' => 120,
            'starts_on' => $threeMonthsAgo->toDateString(),
            'budget_type' => BudgetType::MonthlyCi,
            'budget_amount' => 2000,
            'budget_hours' => 16,
            'budget_starts_on' => $threeMonthsAgo->toDateString(),
        ]);

        // 4. Unbudgeted project, for contrast
        $unbudgeted = $this->upsertProject([
            'client_id' => $filter->id,
            'code' => 'FAL-RND-01',
            'name' => 'Internal R&D',
            'is_billable' => true,
            'default_hourly_rate' => 100,
            'starts_on' => $threeMonthsAgo->toDateString(),
        ]);

        foreach ([$fixedFee, $ciHealthy, $ciOver, $unbudgeted] as $project) {
            $project->tasks()->syncWithoutDetaching([
                $devTask->id => ['is_billable' => true, 'hourly_rate_override' => null],
                $pmTask->id => ['is_billable' => true, 'hourly_rate_override' => null],
            ]);
            $project->users()->syncWithoutDetaching([
                $user->id => ['hourly_rate_override' => null],
            ]);

            // Wipe demo entries so re-running is clean.
            TimeEntry::where('project_id', $project->id)->delete();
        }

        // Fixed-fee: ~120 hours total = £12,000 (60% of £20k)
        $this->seedHours($fixedFee, $devTask, $user, $threeMonthsAgo, $now, totalHours: 120, rate: 100);

        // CI healthy: ~13 hrs/month (under the 15hr/£1500 budget) → £3,900 vs £4,500 cumulative
        $this->seedMonthlyHours($ciHealthy, $devTask, $user, $threeMonthsAgo, $now, hoursPerMonth: 13, rate: 100);

        // CI over: 18, 20, 22 hrs/month at £120 (budget is £2,000/mo) → £7,200 vs £6,000
        $this->seedMonthlyHours($ciOver, $devTask, $user, $threeMonthsAgo, $now, hoursPerMonth: 20, rate: 120);

        // Unbudgeted: ~30 hours
        $this->seedHours($unbudgeted, $pmTask, $user, $threeMonthsAgo, $now, totalHours: 30, rate: 100);

        $this->command?->info('Seeded 4 demo projects with time entries.');
    }

    private function upsertProject(array $attrs): Project
    {
        $project = Project::firstOrNew(['code' => $attrs['code']]);
        $project->fill($attrs);
        $project->save();

        return $project;
    }

    private function seedHours(Project $project, Task $task, User $user, CarbonImmutable $from, CarbonImmutable $to, float $totalHours, float $rate): void
    {
        $days = max(1, $from->diffInDays($to));
        $entryCount = (int) ceil($totalHours / 4); // ~4hr chunks
        $hoursPerEntry = round($totalHours / $entryCount, 2);

        for ($i = 0; $i < $entryCount; $i++) {
            $day = $from->addDays((int) round($i * $days / $entryCount));
            // Skip weekends
            if ($day->isWeekend()) {
                $day = $day->addDays($day->dayOfWeek === 6 ? 2 : 1);
            }
            $this->createEntry($project, $task, $user, $day, $hoursPerEntry, $rate);
        }
    }

    private function seedMonthlyHours(Project $project, Task $task, User $user, CarbonImmutable $from, CarbonImmutable $to, float $hoursPerMonth, float $rate): void
    {
        $cursor = $from;
        while ($cursor->lessThanOrEqualTo($to)) {
            $monthEnd = $cursor->endOfMonth()->lessThan($to) ? $cursor->endOfMonth() : $to;
            $this->seedHours($project, $task, $user, $cursor, $monthEnd, $hoursPerMonth, $rate);
            $cursor = $cursor->addMonth()->startOfMonth();
        }
    }

    private function createEntry(Project $project, Task $task, User $user, CarbonImmutable $day, float $hours, float $rate): void
    {
        TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => $day->toDateString(),
            'hours' => $hours,
            'is_running' => false,
            'is_billable' => true,
            'billable_rate_snapshot' => $rate,
            'billable_amount' => round($hours * $rate, 2),
            'invoiced_at' => null,
        ]);
    }
}
