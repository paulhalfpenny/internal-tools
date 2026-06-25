<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_pending_actions', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable();
            $table->string('subject_state_hash', 64)->nullable();
            $table->json('subject_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_pending_actions', function (Blueprint $table) {
            $table->dropColumn(['payload_hash', 'subject_state_hash', 'subject_snapshot']);
        });
    }
};
