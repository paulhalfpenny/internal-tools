<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asana_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16);
            $table->string('event');
            $table->json('context')->nullable();
            $table->nullableMorphs('subject');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['level', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_sync_logs');
    }
};
