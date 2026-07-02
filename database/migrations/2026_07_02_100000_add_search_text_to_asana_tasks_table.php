<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asana_tasks', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('asana_tasks', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
