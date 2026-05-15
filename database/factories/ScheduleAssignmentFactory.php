<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ScheduleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleAssignment>
 */
class ScheduleAssignmentFactory extends Factory
{
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-1 week', '+2 weeks');

        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'schedule_placeholder_id' => null,
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => (clone $startsOn)->modify('+1 week')->format('Y-m-d'),
            'hours_per_day' => 4.00,
            'notes' => null,
        ];
    }
}
