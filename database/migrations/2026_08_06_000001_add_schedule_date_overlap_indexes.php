<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->index(['ends_on', 'starts_on'], 'schedule_assignments_overlap_dates_index');
        });

        Schema::table('schedule_time_off', function (Blueprint $table) {
            $table->index(['ends_on', 'starts_on'], 'schedule_time_off_overlap_dates_index');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->dropIndex('schedule_assignments_overlap_dates_index');
        });

        Schema::table('schedule_time_off', function (Blueprint $table) {
            $table->dropIndex('schedule_time_off_overlap_dates_index');
        });
    }
};
