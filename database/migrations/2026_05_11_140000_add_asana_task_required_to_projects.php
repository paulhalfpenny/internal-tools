<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // When the project links to one or more Asana boards, should every
            // time entry against that project pick an Asana task? Default true
            // (matches the prior implicit behaviour); admins can disable per
            // project for boards used as reference only.
            $table->boolean('asana_task_required')->default(true)->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('asana_task_required');
        });
    }
};
