<?php

namespace ReneRoscher\ConditionalCache\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use ReneRoscher\ConditionalCache\Events\CacheValueRejected;
use ReneRoscher\ConditionalCache\Tests\Fixtures\SuccessfulResponse;

use function Illuminate\Support\defer;

class ValidatorAndRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 00:00:00');
    }

    public function test_invokable_class_names_are_resolved_from_the_container(): void
    {
        config(['api.success_key' => 'ok']);

        Cache::rememberWhen('valid', 60, fn () => ['ok' => true], SuccessfulResponse::class);
        Cache::rememberWhen('invalid', 60, fn () => ['success' => true], SuccessfulResponse::class);

        $this->assertTrue(Cache::has('valid'));
        $this->assertFalse(Cache::has('invalid'));
    }

    public function test_invalid_validators_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::rememberWhen('key', 60, fn () => 'x', 'This\\Class\\Does\\Not\\Exist');
    }

    public function test_invalid_ttl_closure_results_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::rememberWhen('key', fn () => 'soon', fn () => 'x', fn () => true);
    }

    public function test_invalid_flexible_ttl_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::flexibleWhen('key', [10], fn () => 'x', fn () => true);
    }

    public function test_invalid_flexible_lock_options_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::flexibleWhen('key', [10, 60], fn () => 'x', fn () => true, lock: ['seconds' => '10']);
    }

    public function test_invalid_keys_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::rememberWhen(['not', 'a', 'key'], 60, fn () => 'x');
    }

    public function test_retry_after_skips_the_callback_until_the_window_has_passed(): void
    {
        Event::fake([CacheValueRejected::class]);

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return $calls < 3 ? ['success' => false] : ['success' => true];
        };

        $fetch = fn () => Cache::rememberWhen('api', 3600, $callback, fn ($v) => $v['success'], default: ['fallback'], retryAfter: 30);

        $this->assertSame(['fallback'], $fetch());
        $this->assertSame(['fallback'], $fetch());
        $this->assertSame(1, $calls);
        $this->assertFalse(Cache::has('api'));

        Carbon::setTestNow(now()->addSeconds(31));

        $this->assertSame(['fallback'], $fetch());
        $this->assertSame(2, $calls);

        Carbon::setTestNow(now()->addSeconds(31));

        $this->assertSame(['success' => true], $fetch());
        $this->assertSame(['success' => true], $fetch());
        $this->assertSame(3, $calls);

        Event::assertDispatchedTimes(CacheValueRejected::class, 2);
    }

    public function test_retry_after_can_remember_a_null_default(): void
    {
        $calls = 0;

        $fetch = function () use (&$calls) {
            return Cache::rememberForeverWhen('api', function () use (&$calls) {
                $calls++;

                return null;
            }, default: fn () => null, retryAfter: 30);
        };

        $this->assertNull($fetch());
        $this->assertNull($fetch());
        $this->assertSame(1, $calls);
    }

    public function test_flexible_retry_after_pauses_refreshes(): void
    {
        $validator = fn ($v) => $v !== 'broken';

        Cache::flexibleWhen('flex', [10, 3600], fn () => 'v1', $validator, default: 'fallback', retryAfter: 60);

        Carbon::setTestNow(now()->addSeconds(11));

        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 3600], fn () => 'broken', $validator, default: 'fallback', retryAfter: 60));
        defer()->invoke();

        // Within the retry window no refresh is scheduled at all.
        Carbon::setTestNow(now()->addSeconds(30));
        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 3600], fn () => $this->fail('should not refresh'), $validator, default: 'fallback', retryAfter: 60));
        $this->assertCount(0, defer());

        // After the window the refresh runs again.
        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 3600], fn () => 'v2', $validator, default: 'fallback', retryAfter: 60));
        defer()->invoke();

        $this->assertSame('v2', Cache::get('flex'));
    }

    public function test_retry_after_resolves_a_closure_default_only_once_per_window(): void
    {
        $defaults = 0;
        $fetch = function () use (&$defaults) {
            return Cache::rememberWhen('api', 60, fn () => 'bad', fn () => false, default: function () use (&$defaults) {
                $defaults++;

                return 'fallback';
            }, retryAfter: 30);
        };

        $this->assertSame('fallback', $fetch());
        $this->assertSame('fallback', $fetch());
        $this->assertSame(1, $defaults);
    }

    public function test_flexible_default_on_miss(): void
    {
        $this->assertSame('fallback', Cache::flexibleWhen('flex', [10, 60], fn () => 'bad', fn ($v) => $v === 'ok', default: 'fallback'));
        $this->assertFalse(Cache::has('flex'));
    }

    public function test_flexible_retry_after_on_miss(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return 'broken';
        };

        $this->assertSame('broken', Cache::flexibleWhen('flex', [10, 60], $callback, fn ($v) => $v === 'ok', default: fn ($v) => $v, retryAfter: 30));
        $this->assertSame('broken', Cache::flexibleWhen('flex', [10, 60], $callback, fn ($v) => $v === 'ok', default: fn ($v) => $v, retryAfter: 30));
        $this->assertSame(1, $calls);
    }

    public function test_tagged_caches_keep_retry_markers_inside_the_tag(): void
    {
        Cache::tags(['api'])->rememberWhen('key', 60, fn () => '', default: 'fallback', retryAfter: 30);

        $this->assertTrue(Cache::tags(['api'])->has('conditional-cache:retry:key'));
        $this->assertFalse(Cache::has('conditional-cache:retry:key'));

        Cache::tags(['api'])->flush();

        $this->assertFalse(Cache::tags(['api'])->has('conditional-cache:retry:key'));
    }

    public function test_retry_after_requires_a_default(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a default');

        Cache::rememberWhen('api', 60, fn () => 'bad', fn () => false, retryAfter: 30);
    }

    public function test_flexible_retry_after_requires_a_default(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::flexibleWhen('flex', [10, 60], fn () => 'x', retryAfter: 30);
    }

    public function test_the_rejected_value_can_be_returned_explicitly(): void
    {
        $this->assertSame('bad', Cache::rememberWhen('api', 60, fn () => 'bad', fn () => false, default: fn ($v) => $v, retryAfter: 30));
        $this->assertSame('bad', Cache::rememberWhen('api', 60, fn () => 'other', fn () => false, default: fn ($v) => $v, retryAfter: 30));
    }

    public function test_forget_also_forgets_the_retry_marker(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) {
            return Cache::rememberWhen('api', 60, function () use (&$calls) {
                $calls++;

                return 'bad';
            }, fn () => false, default: 'fallback', retryAfter: 300);
        };

        $fetch();
        $fetch();
        $this->assertSame(1, $calls);

        Cache::forget('api');

        $fetch();
        $this->assertSame(2, $calls);
    }

    public function test_forget_on_a_tagged_cache_forgets_the_tagged_retry_marker(): void
    {
        Cache::tags(['api'])->rememberWhen('key', 60, fn () => '', default: 'fallback', retryAfter: 300);
        $this->assertTrue(Cache::tags(['api'])->has('conditional-cache:retry:key'));

        Cache::tags(['api'])->forget('key');

        $this->assertFalse(Cache::tags(['api'])->has('conditional-cache:retry:key'));
    }

    public function test_forget_on_a_specific_store_forgets_its_retry_marker(): void
    {
        config(['cache.stores.second' => ['driver' => 'array']]);

        Cache::store('second')->rememberWhen('key', 60, fn () => '', default: 'fallback', retryAfter: 300);
        Cache::store('second')->forget('key');

        $this->assertFalse(Cache::store('second')->has('conditional-cache:retry:key'));
    }

    public function test_forget_on_an_unconfigured_store_name_still_works(): void
    {
        $repository = new Repository(new ArrayStore, ['store' => 'not-configured']);
        $repository->setEventDispatcher($this->app['events']);
        $repository->put('key', 'value', 60);

        $this->assertTrue($repository->forget('key'));
    }
}
