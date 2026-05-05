<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_billable')->default(true)->after('default_hourly_rate');
        });

        // Backfill: non_billable rows become is_billable=false; everything else stays true.
        DB::table('projects')
            ->where('billing_type', 'non_billable')
            ->update(['is_billable' => false]);

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('billing_type', ['hourly', 'fixed_fee', 'non_billable'])->default('hourly')->after('default_hourly_rate');
        });

        DB::table('projects')
            ->where('is_billable', false)
            ->update(['billing_type' => 'non_billable']);

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_billable');
        });
    }
};
