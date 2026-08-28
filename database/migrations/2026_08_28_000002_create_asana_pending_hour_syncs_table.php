<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asana_pending_hour_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('asana_task_gid');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 64);
            $table->timestamps();

            $table->unique(['asana_task_gid', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_pending_hour_syncs');
    }
};
