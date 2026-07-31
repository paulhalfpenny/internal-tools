<?php

namespace App\Providers;

use App\Console\Commands\RestartQueue;
use App\Console\Commands\WorkQueue;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class QueueWorkerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkQueue::class, function (Application $app): WorkQueue {
            return new WorkQueue(
                $app['queue.worker'],
                $this->workerCache($app),
            );
        });

        $this->app->singleton(RestartQueue::class, function (Application $app): RestartQueue {
            return new RestartQueue(
                $this->workerCache($app),
                $this->applicationCache($app),
            );
        });
    }

    private function workerCache(Application $app): Repository
    {
        return $app->make(CacheFactory::class)->store(
            $app['config']->get('queue.worker_cache', 'file'),
        );
    }

    private function applicationCache(Application $app): Repository
    {
        return $app->make(CacheFactory::class)->store();
    }
}
