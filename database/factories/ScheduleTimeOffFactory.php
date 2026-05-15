<?php

namespace Database\Factories;

use App\Models\ScheduleTimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleTimeOff>
 */
class ScheduleTimeOffFactory extends Factory
{
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-1 week', '+2 weeks');

        return [
            'user_id' => User::factory(),
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => (clone $startsOn)->modify('+2 days')->format('Y-m-d'),
            'hours_per_day' => 7.50,
            'label' => 'Time off',
            'notes' => null,
        ];
    }
}
