<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'is_default_billable' => true,
            'colour' => '#3B82F6',
            'sort_order' => 0,
            'is_archived' => false,
        ];
    }
}
