<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Set when a token refresh definitively fails. The refresh failure path also
            // disconnects the user (nulling the tokens), so without this stamp there is
            // nothing left to tell a dropped connection from one that never existed.
            $table->timestamp('asana_connection_lost_at')->nullable()->after('asana_workspace_gid');

            // Set when we notify the user about the drop, so the daily check does not nag.
            $table->timestamp('asana_connection_alerted_at')->nullable()->after('asana_connection_lost_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['asana_connection_lost_at', 'asana_connection_alerted_at']);
        });
    }
};
