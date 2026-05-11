<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_asana_links', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('asana_project_gid');
            $table->string('asana_custom_field_gid')->nullable();
            $table->timestamps();

            $table->primary(['project_id', 'asana_project_gid']);
            // One Asana board belongs to at most one internal project.
            $table->unique('asana_project_gid');
            $table->foreign('asana_project_gid')
                ->references('gid')->on('asana_projects')
                ->restrictOnDelete();
        });

        // Backfill from the existing 1:1 columns on projects.
        DB::table('projects')
            ->whereNotNull('asana_project_gid')
            ->orderBy('id')
            ->select(['id', 'asana_project_gid', 'asana_custom_field_gid'])
            ->each(function ($row) {
                DB::table('project_asana_links')->insertOrIgnore([
                    'project_id' => $row->id,
                    'asana_project_gid' => $row->asana_project_gid,
                    'asana_custom_field_gid' => $row->asana_custom_field_gid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_asana_links');
    }
};
