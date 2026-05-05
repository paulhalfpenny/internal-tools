<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('budget_type')->nullable()->after('default_hourly_rate');
            $table->decimal('budget_amount', 10, 2)->nullable()->after('budget_type');
            $table->decimal('budget_hours', 8, 2)->nullable()->after('budget_amount');
            $table->date('budget_starts_on')->nullable()->after('budget_hours');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['budget_type', 'budget_amount', 'budget_hours', 'budget_starts_on']);
        });
    }
};
