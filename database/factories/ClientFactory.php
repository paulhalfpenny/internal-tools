<?php

namespace Database\Factories;

use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'task_billability_profile' => ClientTaskBillabilityProfile::Agency,
            'is_archived' => false,
        ];
    }
}
