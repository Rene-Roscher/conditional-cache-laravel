<?php

namespace ReneRoscher\ConditionalCache\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

class FlexibleWhenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 00:00:00');
    }

    public function test_invalid_value_on_miss_is_not_cached(): void
    {
        $rejected = [];

        $value = Cache::flexibleWhen('flex', [10, 60], fn () => 'bad', fn ($v) => $v === 'ok', function ($value, $key) use (&$rejected) {
            $rejected[] = [$value, $key];
        });

        $this->assertSame('bad', $value);
        $this->assertSame([['bad', 'flex']], $rejected);
        $this->assertFalse(Cache::has('flex'));
    }

    public function test_valid_value_on_miss_is_cached(): void
    {
        $this->assertSame('ok', Cache::flexibleWhen('flex', [10, 60], fn () => 'ok', fn ($v) => $v === 'ok'));
        $this->assertSame('ok', Cache::get('flex'));
        $this->assertCount(0, defer());
    }

    public function test_fresh_value_is_returned_without_refresh(): void
    {
        Cache::flexibleWhen('flex', [10, 60], fn () => 'ok', fn () => true);

        Carbon::setTestNow(now()->addSeconds(5));

        $this->assertSame('ok', Cache::flexibleWhen('flex', [10, 60], fn () => $this->fail('should not run'), fn () => true));
        $this->assertCount(0, defer());
    }

    public function test_invalid_refresh_keeps_previous_value(): void
    {
        Cache::flexibleWhen('flex', [10, 60], fn () => 'v1', fn () => true);

        Carbon::setTestNow(now()->addSeconds(11));

        $rejected = 0;
        $validator = fn ($v) => $v !== 'broken';
        $onInvalid = function () use (&$rejected) {
            $rejected++;
        };

        // Stale hit: returns the old value and defers the refresh.
        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 60], fn () => 'broken', $validator, $onInvalid));
        $this->assertCount(1, defer());

        defer()->invoke();

        $this->assertSame(1, $rejected);
        $this->assertSame('v1', Cache::get('flex'));

        // The refresh is retried on the next stale hit and succeeds this time.
        $this->assertSame('v1', Cache::flexibleWhen('flex', [10, 60], fn () => 'v2', $validator, $onInvalid));
        defer()->invoke();

        $this->assertSame('v2', Cache::get('flex'));
        $this->assertSame('v2', Cache::flexibleWhen('flex', [10, 60], fn () => 'v3', $validator, $onInvalid));
        $this->assertSame(1, $rejected);
    }

    public function test_shares_entries_with_native_flexible(): void
    {
        Cache::flexible('flex', [10, 60], fn () => 'native');

        $this->assertSame('native', Cache::flexibleWhen('flex', [10, 60], fn () => 'other', fn () => true));
    }
}
