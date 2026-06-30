<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_asana_links', function (Blueprint $table) {
            $table->index('asana_project_gid', 'project_asana_links_asana_project_gid_index');
            $table->dropUnique('project_asana_links_asana_project_gid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('project_asana_links', function (Blueprint $table) {
            $table->unique('asana_project_gid');
            $table->dropIndex('project_asana_links_asana_project_gid_index');
        });
    }
};
