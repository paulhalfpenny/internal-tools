<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('manager_user_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('project_budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('threshold');
            $table->string('period_key'); // 'lifetime' or 'YYYY-MM' for monthly_ci
            $table->timestamp('alerted_at');
            $table->timestamps();

            $table->unique(['project_id', 'threshold', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budget_alerts');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_user_id');
        });
    }
};
