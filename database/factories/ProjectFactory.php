<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'code' => strtoupper(fake()->unique()->bothify('???###')),
            'name' => fake()->words(3, true),
            'is_billable' => true,
            'default_hourly_rate' => 84.00,
            'starts_on' => null,
            'ends_on' => null,
            'is_archived' => false,
        ];
    }

    public function nonBillable(): static
    {
        return $this->state(fn (array $attributes) => ['is_billable' => false]);
    }
}
