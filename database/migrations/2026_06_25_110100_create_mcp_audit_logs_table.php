<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pending_action_id')->nullable()->constrained('mcp_pending_actions')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('tool_name');
            $table->string('action');
            $table->string('risk_level')->default('standard');
            $table->string('status');
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'status']);
            $table->index(['pending_action_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
