<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('schedule_preferences')->nullable()->after('schedule_work_days');
        });

        $now = now();
        foreach ($this->fixedTeams() as $team) {
            $existingId = DB::table('teams')->where('name', $team['name'])->value('id');

            if ($existingId !== null) {
                DB::table('teams')
                    ->where('id', $existingId)
                    ->update([
                        'description' => $team['description'],
                        'colour' => $team['colour'],
                        'is_archived' => false,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('teams')->insert([
                ...$team,
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('schedule_preferences');
        });
    }

    /**
     * @return array<int, array{name: string, description: string, colour: string}>
     */
    private function fixedTeams(): array
    {
        return [
            [
                'name' => 'JDW',
                'description' => 'JDW delivery team',
                'colour' => '#0F766E',
            ],
            [
                'name' => 'Agency',
                'description' => 'Agency team',
                'colour' => '#2563EB',
            ],
        ];
    }
};
