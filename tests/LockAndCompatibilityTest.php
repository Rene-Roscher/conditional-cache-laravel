<?php

namespace ReneRoscher\ConditionalCache\Tests;

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReneRoscher\ConditionalCache\ConditionalCache;
use ReneRoscher\ConditionalCache\ConditionalCacheMixin;

class LockAndCompatibilityTest extends TestCase
{
    public function test_lock_computes_and_caches_valid_values(): void
    {
        $this->assertSame('ok', Cache::rememberWhen('key', 60, fn () => 'ok', fn () => true, lock: 5));
        $this->assertSame('ok', Cache::get('key'));
        // The lock was released again.
        $this->assertTrue(Cache::lock(ConditionalCache::LOCK_KEY_PREFIX.'key', 1)->get());
    }

    public function test_lock_rechecks_the_cache_after_acquiring_the_lock(): void
    {
        // Simulate another process that stores the value right after our first lookup missed.
        Event::listen(CacheMissed::class, function (CacheMissed $event) {
            if ($event->key === 'key') {
                Cache::put('key', 'from-other-process', 60);
            }
        });

        $calls = 0;

        $result = Cache::rememberWhen('key', 60, function () use (&$calls) {
            $calls++;

            return 'computed';
        }, fn () => true, lock: 5);

        $this->assertSame('from-other-process', $result);
        $this->assertSame(0, $calls);
    }

    public function test_lock_falls_back_to_computing_when_it_cannot_be_acquired(): void
    {
        $lock = Cache::lock(ConditionalCache::LOCK_KEY_PREFIX.'key', 10);
        $lock->get();

        $started = microtime(true);
        $this->assertSame('ok', Cache::rememberWhen('key', 60, fn () => 'ok', fn () => true, lock: 1));
        // It waited for the lock (Laravel polls every 250ms) before computing without it.
        $this->assertGreaterThanOrEqual(0.5, microtime(true) - $started);

        $lock->release();
    }

    public function test_lock_must_be_at_least_one_second(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::rememberWhen('key', 60, fn () => 'ok', fn () => true, lock: 0);
    }

    public function test_lock_works_with_remember_forever_when(): void
    {
        $this->assertSame('ok', Cache::rememberForeverWhen('key', fn () => 'ok', fn () => true, lock: 5));
        $this->assertSame('ok', Cache::get('key'));
    }

    /**
     * Macros are only reachable while Laravel has no real method of the same name.
     * If this fails after a Laravel upgrade, the framework added a conflicting method.
     */
    public function test_macro_names_do_not_collide_with_native_repository_methods(): void
    {
        $macros = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(ConditionalCacheMixin::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach ($macros as $macro) {
            $this->assertFalse(method_exists(Repository::class, $macro), "Laravel now has a native Repository::{$macro}().");
            $this->assertTrue(Repository::hasMacro($macro));
        }
    }
}
