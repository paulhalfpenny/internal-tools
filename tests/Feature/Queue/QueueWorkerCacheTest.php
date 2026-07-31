<?php

use App\Console\Commands\RestartQueue;
use App\Console\Commands\WorkQueue;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function queueCommandCache(object $command): Repository
{
    $property = new ReflectionProperty($command, 'cache');

    /** @var Repository $cache */
    $cache = $property->getValue($command);

    return $cache;
}

test('queue commands use the dedicated worker cache instead of the application cache', function () {
    config([
        'cache.default' => 'array',
        'queue.worker_cache' => 'file',
    ]);

    $workerCache = Cache::store('file');
    $commands = Artisan::all();

    expect($commands['queue:work'])->toBeInstanceOf(WorkQueue::class)
        ->and($commands['queue:restart'])->toBeInstanceOf(RestartQueue::class)
        ->and(queueCommandCache($commands['queue:work']))->toBe($workerCache)
        ->and(queueCommandCache($commands['queue:restart']))->toBe($workerCache)
        ->and($workerCache)->not->toBe(Cache::store());
});

test('queue restart writes its signal to the dedicated and legacy caches', function () {
    config([
        'cache.default' => 'array',
        'queue.worker_cache' => 'file',
    ]);

    $key = 'illuminate:queue:restart';
    Cache::store('file')->forget($key);
    Cache::store('array')->forget($key);

    $this->artisan('queue:restart')->assertSuccessful();

    expect(Cache::store('file')->get($key))->toBeInt()
        ->and(Cache::store('array')->get($key))->toBeInt();

    Cache::store('file')->forget($key);
    Cache::store('array')->forget($key);
});

test('queue restart succeeds when the application cache is unavailable', function () {
    Cache::extend('unavailable', fn () => new CacheRepository(new class extends ArrayStore
    {
        public function forever($key, $value)
        {
            throw new RuntimeException('Application cache is unavailable.');
        }
    }));

    config([
        'cache.default' => 'unavailable',
        'cache.stores.unavailable' => ['driver' => 'unavailable'],
        'queue.worker_cache' => 'array',
    ]);

    $key = 'illuminate:queue:restart';
    Cache::store('array')->forget($key);

    $this->artisan('queue:restart')->assertSuccessful();

    expect(Cache::store('array')->get($key))->toBeInt();

    Cache::store('array')->forget($key);
});
