<?php

namespace ReneRoscher\ConditionalCache\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReneRoscher\ConditionalCache\Events\CacheValueRejected;
use stdClass;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Runs the core behaviour against real stores (Redis, database, file) instead of the
 * array store, to catch differences in serialization, many() and locks.
 */
class StoreIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.cache', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 1,
        ]);

        $app['config']->set('cache.prefix', 'conditional_cache_tests_');
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'cache']);
        $app['config']->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache', 'connection' => 'sqlite', 'lock_table' => 'cache_locks']);
        $app['config']->set('cache.stores.file', ['driver' => 'file', 'path' => sys_get_temp_dir().'/conditional-cache-tests', 'lock_path' => sys_get_temp_dir().'/conditional-cache-tests']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stores(): array
    {
        return ['redis' => ['redis'], 'database' => ['database'], 'file' => ['file']];
    }

    private function useStore(string $store): void
    {
        if ($store === 'redis') {
            $unavailable = ! extension_loaded('redis') ? 'The redis extension is not installed.' : null;

            try {
                $unavailable ??= Cache::store('redis')->getStore()->connection()->ping() ? null : 'Redis did not answer.';
            } catch (Throwable $e) {
                $unavailable = 'Redis is not reachable: '.$e->getMessage();
            }

            if ($unavailable !== null) {
                env('REQUIRE_REDIS') ? $this->fail($unavailable) : $this->markTestSkipped($unavailable);
            }
        }

        if ($store === 'database') {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });

            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        $this->app['config']->set('cache.default', $store);
        Cache::store($store)->flush();
    }

    #[DataProvider('stores')]
    public function test_valid_values_of_every_type_are_cached_and_invalid_ones_are_not(string $store): void
    {
        $this->useStore($store);

        $object = new stdClass;
        $object->name = 'value';

        foreach ([
            'array' => ['success' => true, 'data' => [1, 2, 3]],
            'object' => $object,
            'collection' => new Collection([1, 2]),
            'false' => false,
            'zero' => 0,
            'string' => 'text',
        ] as $key => $value) {
            Cache::rememberWhen($key, 60, fn () => $value, fn () => true);
            $this->assertEquals($value, Cache::get($key), "{$store}: {$key}");

            $this->assertEquals($value, Cache::rememberWhen($key, 60, fn () => 'recomputed', fn () => true), "{$store}: {$key} hit");
        }

        $this->assertSame(['fallback'], Cache::rememberWhen('invalid', 60, fn () => ['success' => false], fn () => false, default: ['fallback']));
        $this->assertFalse(Cache::has('invalid'));
    }

    #[DataProvider('stores')]
    public function test_retry_after_and_forget(string $store): void
    {
        $this->useStore($store);

        $calls = 0;
        $fetch = function () use (&$calls) {
            return Cache::rememberWhen('api', 60, function () use (&$calls) {
                $calls++;

                return ['success' => false];
            }, fn ($v) => $v['success'], default: ['fallback' => true], retryAfter: 300);
        };

        $this->assertSame(['fallback' => true], $fetch());
        $this->assertSame(['fallback' => true], $fetch());
        $this->assertSame(1, $calls);

        Cache::forget('api');

        $fetch();
        $this->assertSame(2, $calls);
    }

    #[DataProvider('stores')]
    public function test_lock(string $store): void
    {
        $this->useStore($store);

        $this->assertSame('ok', Cache::rememberWhen('locked', 60, fn () => 'ok', fn () => true, lock: 5));
        $this->assertSame('ok', Cache::get('locked'));

        // The lock was released again.
        $lock = Cache::lock('conditional-cache:lock:locked', 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    #[DataProvider('stores')]
    public function test_flexible_when(string $store): void
    {
        $this->useStore($store);
        Carbon::setTestNow(Carbon::now());

        $validator = fn ($v) => $v !== 'broken';

        $this->assertSame('fallback', Cache::flexibleWhen('flex', [10, 3600], fn () => 'broken', $validator, default: 'fallback'));
        $this->assertFalse(Cache::has('flex'));

        Cache::flexibleWhen('flex', [10, 3600], fn () => 'v1', $validator);

        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 3600], fn () => 'broken', $validator));
        defer()->invoke();
        $this->assertSame('v1', Cache::get('flex'));

        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 3600], fn () => 'v2', $validator));
        defer()->invoke();
        $this->assertSame('v2', Cache::get('flex'));

        Carbon::setTestNow();
    }

    #[DataProvider('stores')]
    public function test_rejected_event_carries_the_store_name(string $store): void
    {
        $this->useStore($store);
        Event::fake([CacheValueRejected::class]);

        Cache::rememberWhen('key', 60, fn () => '', default: 'fallback');

        Event::assertDispatched(CacheValueRejected::class, fn (CacheValueRejected $event) => $event->storeName === $store);
    }

    public function test_redis_tags(): void
    {
        $this->useStore('redis');

        Cache::tags(['api'])->rememberWhen('key', 60, fn () => 'ok', fn () => true);
        $this->assertSame('ok', Cache::tags(['api'])->get('key'));

        Cache::tags(['api'])->rememberWhen('bad', 60, fn () => '', default: 'fallback', retryAfter: 300);
        $this->assertTrue(Cache::tags(['api'])->has('conditional-cache:retry:bad'));

        Cache::tags(['api'])->forget('bad');
        $this->assertFalse(Cache::tags(['api'])->has('conditional-cache:retry:bad'));
    }
}
