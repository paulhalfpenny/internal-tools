<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'task_id' => Task::factory(),
            'spent_on' => Carbon::today()->toDateString(),
            'hours' => 1.00,
            'notes' => null,
            'is_running' => false,
            'timer_started_at' => null,
            'is_billable' => true,
            'billable_rate_snapshot' => 84.00,
            'billable_amount' => 84.00,
            'invoiced_at' => null,
            'external_reference' => null,
        ];
    }
}
