<?php

namespace ReneRoscher\ConditionalCache\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReneRoscher\ConditionalCache\Events\CacheValueRejected;
use RuntimeException;

enum CacheKey: string
{
    case Api = 'api-data';
}

class RememberWhenTest extends TestCase
{
    public function test_valid_values_are_cached(): void
    {
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return ['success' => true, 'data' => [1, 2, 3]];
        };

        $validator = fn ($value) => ! empty($value) && ($value['success'] ?? false) === true;

        $first = Cache::rememberWhen('api-data', 3600, $callback, $validator);
        $second = Cache::rememberWhen('api-data', 3600, $callback, $validator);

        $this->assertSame(['success' => true, 'data' => [1, 2, 3]], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertSame($first, Cache::get('api-data'));
    }

    public function test_invalid_values_are_returned_but_not_cached(): void
    {
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return ['success' => false];
        };

        $validator = fn ($value) => ($value['success'] ?? false) === true;

        $this->assertSame(['success' => false], Cache::rememberWhen('api-data', 3600, $callback, $validator));
        $this->assertSame(['success' => false], Cache::rememberWhen('api-data', 3600, $callback, $validator));

        $this->assertSame(2, $calls);
        $this->assertFalse(Cache::has('api-data'));
    }

    public function test_cached_values_skip_callback_and_validator(): void
    {
        Cache::put('api-data', 'cached', 60);

        $result = Cache::rememberWhen(
            'api-data',
            60,
            fn () => throw new RuntimeException('callback should not run'),
            fn () => throw new RuntimeException('validator should not run'),
        );

        $this->assertSame('cached', $result);
    }

    public function test_on_invalid_callback_receives_value_and_key(): void
    {
        $received = null;

        Cache::rememberWhen('api-data', 60, fn () => ['success' => false], fn () => false, function ($value, $key) use (&$received) {
            $received = [$value, $key];
        });

        $this->assertSame([['success' => false], 'api-data'], $received);
    }

    public function test_on_invalid_callback_is_not_called_for_valid_values(): void
    {
        Cache::rememberWhen('api-data', 60, fn () => 'ok', fn () => true, fn () => $this->fail('onInvalid should not run'));

        $this->assertSame('ok', Cache::get('api-data'));
    }

    public function test_on_invalid_return_value_is_used_as_fallback_and_not_cached(): void
    {
        $result = Cache::rememberWhen('api-data', 60, fn () => ['success' => false], fn () => false, fn () => ['fallback']);

        $this->assertSame(['fallback'], $result);
        $this->assertFalse(Cache::has('api-data'));
    }

    public function test_on_invalid_can_throw(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API is down');

        Cache::rememberWhen('api-data', 60, fn () => null, fn ($value) => $value !== null, fn () => throw new RuntimeException('API is down'));
    }

    public function test_without_validator_only_filled_values_are_cached(): void
    {
        foreach (['', '   ', [], null] as $blank) {
            Cache::rememberWhen('blank', 60, fn () => $blank);
            $this->assertFalse(Cache::has('blank'));
        }

        foreach ([0, false, 'x', [1]] as $filled) {
            Cache::forget('filled');
            Cache::rememberWhen('filled', 60, fn () => $filled);
            $this->assertTrue(Cache::has('filled'));
        }
    }

    public function test_native_callables_can_be_used_as_validator(): void
    {
        Cache::rememberWhen('string', 60, fn () => 'x', is_array(...));
        Cache::rememberWhen('array', 60, fn () => ['x'], 'is_array');

        $this->assertFalse(Cache::has('string'));
        $this->assertTrue(Cache::has('array'));
    }

    public function test_blank_collections_are_not_cached_by_default(): void
    {
        Cache::rememberWhen('collection', 60, fn () => collect());

        $this->assertFalse(Cache::has('collection'));
    }

    public function test_named_arguments_work_through_the_facade(): void
    {
        $rejected = false;

        Cache::rememberWhen(
            key: 'api-data',
            ttl: 60,
            callback: fn () => 'nope',
            validator: fn ($value) => $value === 'ok',
            onInvalid: function () use (&$rejected) {
                $rejected = true;
            },
        );

        $this->assertTrue($rejected);
        $this->assertFalse(Cache::has('api-data'));

        // Skipping the validator (default: filled) and passing only onInvalid.
        $this->assertSame('fallback', Cache::rememberWhen('api-data', 60, fn () => [], onInvalid: fn () => 'fallback'));
        $this->assertFalse(Cache::has('api-data'));
    }

    public function test_ttl_closure_receives_the_value(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        Cache::rememberWhen('short', fn ($value) => $value['ttl'], fn () => ['ttl' => 10], fn () => true);
        Cache::rememberWhen('long', fn ($value) => $value['ttl'], fn () => ['ttl' => 100], fn () => true);

        Carbon::setTestNow('2026-01-01 00:00:11');

        $this->assertFalse(Cache::has('short'));
        $this->assertTrue(Cache::has('long'));
    }

    public function test_works_with_enum_keys(): void
    {
        $key = null;

        Cache::rememberWhen(CacheKey::Api, 60, fn () => 'ok', fn () => true);
        Cache::rememberWhen('other', 60, fn () => 'x', fn () => false, function ($value, $k) use (&$key) {
            $key = $k;
        });

        $this->assertSame('ok', Cache::get('api-data'));
        $this->assertSame('other', $key);
    }

    public function test_works_with_specific_stores_and_tags(): void
    {
        Cache::store('array')->rememberWhen('store', 60, fn () => 'ok', fn () => true);
        Cache::tags(['api'])->rememberWhen('tagged', 60, fn () => 'ok', fn () => true);
        Cache::tags(['api'])->rememberWhen('tagged-invalid', 60, fn () => 'bad', fn () => false);

        $this->assertSame('ok', Cache::store('array')->get('store'));
        $this->assertSame('ok', Cache::tags(['api'])->get('tagged'));
        $this->assertFalse(Cache::tags(['api'])->has('tagged-invalid'));
    }

    public function test_rejected_event_is_dispatched(): void
    {
        Event::fake([CacheValueRejected::class]);

        Cache::rememberWhen('valid', 60, fn () => 'ok', fn () => true);
        Cache::rememberWhen('api-data', 60, fn () => ['success' => false], fn () => false);

        Event::assertDispatchedTimes(CacheValueRejected::class, 1);
        Event::assertDispatched(CacheValueRejected::class, fn (CacheValueRejected $event) => $event->key === 'api-data'
            && $event->value === ['success' => false]
            && $event->storeName === 'array'
            && $event->method === 'rememberWhen');
    }

    public function test_remember_forever_when(): void
    {
        $this->assertSame('bad', Cache::rememberForeverWhen('forever', fn () => 'bad', fn ($v) => $v === 'ok'));
        $this->assertFalse(Cache::has('forever'));

        $this->assertSame('ok', Cache::rememberForeverWhen('forever', fn () => 'ok', fn ($v) => $v === 'ok'));
        $this->assertSame('ok', Cache::rememberForeverWhen('forever', fn () => 'other', fn () => true));

        $this->assertSame('fallback', Cache::rememberForeverWhen('forever-2', fn () => 'bad', fn () => false, fn () => 'fallback'));
    }
}
