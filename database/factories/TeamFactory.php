<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Development', 'Design', 'Strategy', 'Project Management', 'QA']).' '.fake()->unique()->numberBetween(1, 999),
            'description' => fake()->optional()->sentence(),
            'colour' => fake()->hexColor(),
            'is_archived' => false,
        ];
    }
}
