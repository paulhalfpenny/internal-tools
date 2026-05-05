<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('asana_task_gid')->nullable()->after('external_reference');
            $table->timestamp('asana_synced_at')->nullable()->after('asana_task_gid');
            $table->string('asana_sync_error', 512)->nullable()->after('asana_synced_at');

            $table->index('asana_task_gid');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['asana_task_gid']);
            $table->dropColumn(['asana_task_gid', 'asana_synced_at', 'asana_sync_error']);
        });
    }
};
