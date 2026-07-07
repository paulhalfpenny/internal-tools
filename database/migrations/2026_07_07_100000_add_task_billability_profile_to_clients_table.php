<?php

use App\Enums\ClientTaskBillabilityProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('task_billability_profile')
                ->default(ClientTaskBillabilityProfile::Agency->value)
                ->after('code');
        });

        DB::table('clients')
            ->where('name', 'like', 'JDW%')
            ->orWhere('code', 'JDW')
            ->update(['task_billability_profile' => ClientTaskBillabilityProfile::Jdw->value]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('task_billability_profile');
        });
    }
};
