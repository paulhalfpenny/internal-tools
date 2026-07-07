<?php

namespace Database\Seeders;

use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use Illuminate\Database\Seeder;

class SampleClientSeeder extends Seeder
{
    public function run(): void
    {
        $clients = [
            ['name' => 'JDW Projects', 'code' => 'JDW', 'task_billability_profile' => ClientTaskBillabilityProfile::Jdw],
            ['name' => 'JDW Management', 'code' => null, 'task_billability_profile' => ClientTaskBillabilityProfile::Jdw],
            ['name' => 'AAB', 'code' => 'AAB', 'task_billability_profile' => ClientTaskBillabilityProfile::Agency],
            ['name' => 'Agile Business Consortium', 'code' => 'ABC', 'task_billability_profile' => ClientTaskBillabilityProfile::Agency],
            ['name' => 'Filter Agency', 'code' => 'FAL', 'task_billability_profile' => ClientTaskBillabilityProfile::Agency],
        ];

        foreach ($clients as $data) {
            Client::firstOrCreate(['name' => $data['name']], [
                'code' => $data['code'],
                'task_billability_profile' => $data['task_billability_profile'],
            ]);
        }
    }
}
