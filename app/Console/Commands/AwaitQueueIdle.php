<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AwaitQueueIdle extends Command
{
    protected $signature = 'queue:await-idle
        {--timeout=180 : Seconds to wait for in-flight jobs to finish}
        {--poll=2 : Seconds between checks}';

    protected $description = 'Block until no queue job is mid-execution, so a backup taken next is a true point-in-time snapshot.';

    public function handle(): int
    {
        $connection = config('queue.default');

        // Reserved-job inspection is specific to the database driver. Failing loudly
        // beats quietly skipping the drain: the deploy relies on this to guarantee
        // nothing writes between the dump and the migration.
        if ($connection !== 'database') {
            $this->error("queue:await-idle only supports the database queue driver, but queue.default is '{$connection}'.");
            $this->error('Update this command to drain the new driver before relying on it in deploys.');

            return self::FAILURE;
        }

        if (! app()->isDownForMaintenance()) {
            // Workers pause on their own while the app is down (Worker::daemonShouldRun
            // skips work unless --force). Outside maintenance they keep reserving new
            // jobs, so a drain may never converge.
            $this->warn('App is not in maintenance mode — workers may keep reserving jobs while this waits.');
        }

        $timeout = max(0, (int) $this->option('timeout'));
        $poll = max(1, (int) $this->option('poll'));
        $waited = 0;

        while (true) {
            $inFlight = $this->inFlightJobs();

            if ($inFlight === 0) {
                $this->info($waited === 0
                    ? 'Queue is idle.'
                    : "Queue drained after {$waited}s.");

                return self::SUCCESS;
            }

            if ($waited >= $timeout) {
                $this->error("Timed out after {$timeout}s with {$inFlight} job(s) still in flight.");

                return self::FAILURE;
            }

            $this->line("Waiting for {$inFlight} in-flight job(s)…");
            sleep($poll);
            $waited += $poll;
        }
    }

    private function inFlightJobs(): int
    {
        // reserved_at is stamped when a worker picks a job up and cleared when it
        // finishes, so a non-null value means a job is actively executing.
        return DB::table('jobs')->whereNotNull('reserved_at')->count();
    }
}
