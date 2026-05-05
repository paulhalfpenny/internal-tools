<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asana_workspaces', function (Blueprint $table) {
            $table->string('gid')->primary();
            $table->string('name');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asana_projects', function (Blueprint $table) {
            $table->string('gid')->primary();
            $table->string('workspace_gid')->index();
            $table->string('name');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asana_tasks', function (Blueprint $table) {
            $table->string('gid')->primary();
            $table->string('asana_project_gid')->index();
            $table->string('name');
            $table->boolean('is_completed')->default(false);
            $table->string('parent_gid')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_tasks');
        Schema::dropIfExists('asana_projects');
        Schema::dropIfExists('asana_workspaces');
    }
};
