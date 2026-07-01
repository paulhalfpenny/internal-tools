<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('is_jdw_default_billable')
                ->default(true)
                ->after('is_default_billable');
        });

        DB::statement('UPDATE tasks SET is_jdw_default_billable = is_default_billable');
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('is_jdw_default_billable');
        });
    }
};
