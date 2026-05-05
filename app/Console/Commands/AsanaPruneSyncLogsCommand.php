<?php

namespace App\Console\Commands;

use App\Models\AsanaSyncLog;
use Illuminate\Console\Command;

class AsanaPruneSyncLogsCommand extends Command
{
    protected $signature = 'asana:prune-logs {--days=30 : Retention window in days}';

    protected $description = 'Delete asana_sync_logs entries older than the retention window.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = AsanaSyncLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
