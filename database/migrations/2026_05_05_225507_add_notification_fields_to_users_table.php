<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('slack_user_id')->nullable()->after('google_sub');
            $table->date('notifications_paused_until')->nullable()->after('last_login_at');
            $table->boolean('email_notifications_enabled')->default(true)->after('notifications_paused_until');
            $table->boolean('slack_notifications_enabled')->default(true)->after('email_notifications_enabled');
            $table->foreignId('reports_to_user_id')->nullable()->after('slack_notifications_enabled')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('users')->where('weekly_capacity_hours', 37.50)->update(['weekly_capacity_hours' => 40.00]);

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('weekly_capacity_hours', 5, 2)->default(40.00)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('weekly_capacity_hours', 5, 2)->default(37.50)->change();
            $table->dropConstrainedForeignId('reports_to_user_id');
            $table->dropColumn([
                'slack_user_id',
                'notifications_paused_until',
                'email_notifications_enabled',
                'slack_notifications_enabled',
            ]);
        });
    }
};
