<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamp('created_at');

            $table->index(['time_entry_id', 'created_at']);
            $table->index(['changed_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_audits');
    }
};
