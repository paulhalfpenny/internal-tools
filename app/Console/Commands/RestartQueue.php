<?php

namespace App\Console\Commands;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Console\RestartCommand;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RestartQueue extends RestartCommand
{
    public function __construct(
        Repository $workerCache,
        private readonly Repository $applicationCache,
    ) {
        parent::__construct($workerCache);
    }

    public function handle(): int
    {
        $timestamp = $this->currentTime();

        $this->cache->forever('illuminate:queue:restart', $timestamp);

        // Notify workers from the previous release during the transition from
        // the application cache. Failure here is non-fatal: current workers
        // already have their signal in the dedicated, database-free store.
        if ($this->applicationCache !== $this->cache) {
            try {
                $this->applicationCache->forever('illuminate:queue:restart', $timestamp);
            } catch (Throwable $exception) {
                Log::warning('Could not write the legacy queue restart signal to the application cache.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->components->info('Broadcasting queue restart signal.');

        return self::SUCCESS;
    }
}
