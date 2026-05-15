<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('schedule_work_days')->nullable()->after('weekly_capacity_hours');
        });

        Schema::create('schedule_placeholders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role_title')->nullable();
            $table->decimal('weekly_capacity_hours', 5, 2)->default(40.00);
            $table->json('schedule_work_days')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
            $table->index('name');
        });

        Schema::create('schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_placeholder_id')->nullable()->constrained('schedule_placeholders')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('hours_per_day', 4, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'starts_on', 'ends_on']);
            $table->index(['user_id', 'starts_on', 'ends_on']);
            $table->index(['schedule_placeholder_id', 'starts_on', 'ends_on'], 'schedule_assignments_placeholder_dates_index');
        });

        Schema::create('schedule_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('hours_per_day', 4, 2);
            $table->string('label')->default('Time off');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_time_off');
        Schema::dropIfExists('schedule_assignments');
        Schema::dropIfExists('schedule_placeholders');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('schedule_work_days');
        });
    }
};
