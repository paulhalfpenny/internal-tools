<?php

namespace Database\Factories;

use App\Models\SchedulePlaceholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulePlaceholder>
 */
class SchedulePlaceholderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->jobTitle(),
            'role_title' => fake()->randomElement(['Designer', 'Developer', 'Producer', null]),
            'weekly_capacity_hours' => 40.00,
            'schedule_work_days' => [1, 2, 3, 4, 5],
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
