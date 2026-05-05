<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('asana_access_token')->nullable()->after('google_token_expires_at');
            $table->text('asana_refresh_token')->nullable()->after('asana_access_token');
            $table->timestamp('asana_token_expires_at')->nullable()->after('asana_refresh_token');
            $table->string('asana_user_gid')->nullable()->after('asana_token_expires_at');
            $table->string('asana_workspace_gid')->nullable()->after('asana_user_gid');

            $table->index('asana_user_gid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['asana_user_gid']);
            $table->dropColumn([
                'asana_access_token',
                'asana_refresh_token',
                'asana_token_expires_at',
                'asana_user_gid',
                'asana_workspace_gid',
            ]);
        });
    }
};
