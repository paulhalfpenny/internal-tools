<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('hourly_rate', 8, 2);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rate_id')->nullable()->after('default_hourly_rate')->constrained('rates')->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('rate_id')->nullable()->after('default_hourly_rate')->constrained('rates')->nullOnDelete();
        });

        Schema::table('project_user', function (Blueprint $table) {
            $table->foreignId('rate_id')->nullable()->after('hourly_rate_override')->constrained('rates')->nullOnDelete();
        });

        Schema::table('project_task', function (Blueprint $table) {
            $table->foreignId('rate_id')->nullable()->after('hourly_rate_override')->constrained('rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_task', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_id');
        });

        Schema::table('project_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_id');
        });

        Schema::dropIfExists('rates');
    }
};
