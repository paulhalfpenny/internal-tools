<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'google_sub' => fake()->unique()->uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::User,
            'role_title' => null,
            'is_contractor' => false,
            'default_hourly_rate' => null,
            'weekly_capacity_hours' => 40.00,
            'is_active' => true,
            'last_login_at' => null,
            'email_notifications_enabled' => true,
            'slack_notifications_enabled' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Admin]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Manager]);
    }
}
