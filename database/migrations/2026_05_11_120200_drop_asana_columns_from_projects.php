<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['asana_project_gid']);
            $table->dropColumn(['asana_project_gid', 'asana_workspace_gid', 'asana_custom_field_gid']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('asana_project_gid')->nullable()->unique()->after('is_archived');
            $table->string('asana_workspace_gid')->nullable()->after('asana_project_gid');
            $table->string('asana_custom_field_gid')->nullable()->after('asana_workspace_gid');
        });
    }
};
