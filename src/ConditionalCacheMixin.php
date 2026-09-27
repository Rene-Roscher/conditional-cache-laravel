<?php

namespace ReneRoscher\ConditionalCache;

use Closure;
use Illuminate\Support\Carbon;

use function Illuminate\Support\defer;
use function Illuminate\Support\enum_value;

/**
 * Macros mixed into \Illuminate\Cache\Repository.
 *
 * Every returned closure is bound to the cache repository instance, so `$this`
 * refers to the repository (including tagged caches) the macro was called on.
 *
 * @mixin \Illuminate\Cache\Repository
 */
class ConditionalCacheMixin
{
    /**
     * Get an item from the cache, or execute the callback and store the result
     * only if it passes the validator.
     *
     * @return Closure(\UnitEnum|string, Closure|\DateTimeInterface|\DateInterval|int|null, Closure, (callable(mixed): bool)|null, (callable(mixed, string): mixed)|null): mixed
     */
    public function rememberWhen(): Closure
    {
        return function ($key, $ttl, Closure $callback, ?callable $validator = null, ?callable $onInvalid = null) {
            /** @var \Illuminate\Cache\Repository $this */
            $key = enum_value($key);

            $value = $this->get($key);

            if (! is_null($value)) {
                return $value;
            }

            $value = $callback();

            if (! ConditionalCache::passes($value, $validator)) {
                return ConditionalCache::reject($this, $key, $value, $onInvalid, 'rememberWhen');
            }

            $this->put($key, $value, value($ttl, $value));

            return $value;
        };
    }

    /**
     * Get an item from the cache, or execute the callback and store the result
     * forever, but only if it passes the validator.
     *
     * @return Closure(\UnitEnum|string, Closure, (callable(mixed): bool)|null, (callable(mixed, string): mixed)|null): mixed
     */
    public function rememberForeverWhen(): Closure
    {
        return function ($key, Closure $callback, ?callable $validator = null, ?callable $onInvalid = null) {
            /** @var \Illuminate\Cache\Repository $this */
            $key = enum_value($key);

            $value = $this->get($key);

            if (! is_null($value)) {
                return $value;
            }

            $value = $callback();

            if (! ConditionalCache::passes($value, $validator)) {
                return ConditionalCache::reject($this, $key, $value, $onInvalid, 'rememberForeverWhen');
            }

            $this->forever($key, $value);

            return $value;
        };
    }

    /**
     * Stale-while-revalidate (like Cache::flexible), but values are only written
     * when they pass the validator.
     *
     * - Cache miss + invalid value: nothing is stored, the value is returned.
     * - Stale value + invalid refresh: the previous (valid) value is kept untouched
     *   and the refresh is retried on the next stale hit.
     *
     * @return Closure(\UnitEnum|string, array{0: \DateTimeInterface|\DateInterval|int, 1: \DateTimeInterface|\DateInterval|int}, callable, (callable(mixed): bool)|null, (callable(mixed, string): mixed)|null, array{seconds?: int, owner?: string}|null, bool): mixed
     */
    public function flexibleWhen(): Closure
    {
        return function ($key, $ttl, callable $callback, ?callable $validator = null, ?callable $onInvalid = null, $lock = null, $alwaysDefer = false) {
            /** @var \Illuminate\Cache\Repository $this */
            $key = enum_value($key);

            // Same bookkeeping key as Cache::flexible(), so both can share an entry.
            $createdKey = 'illuminate:cache:flexible:created:'.$key;

            [$key => $value, $createdKey => $created] = $this->many([$key, $createdKey]);

            if (in_array(null, [$value, $created], true)) {
                $value = value($callback);

                if (! ConditionalCache::passes($value, $validator)) {
                    return ConditionalCache::reject($this, $key, $value, $onInvalid, 'flexibleWhen');
                }

                $this->putMany([
                    $key => $value,
                    $createdKey => Carbon::now()->getTimestamp(),
                ], $ttl[1]);

                return $value;
            }

            if (($created + $this->getSeconds($ttl[0])) > Carbon::now()->getTimestamp()) {
                return $value;
            }

            $refresh = function () use ($key, $createdKey, $ttl, $callback, $validator, $onInvalid, $lock, $created) {
                $this->getStore()->lock(
                    "illuminate:cache:flexible:lock:{$this->itemKey($key)}",
                    $lock['seconds'] ?? 0,
                    $lock['owner'] ?? null,
                )->get(function () use ($key, $createdKey, $ttl, $callback, $validator, $onInvalid, $created) {
                    if ($created !== $this->get($createdKey)) {
                        return;
                    }

                    $value = value($callback);

                    if (! ConditionalCache::passes($value, $validator)) {
                        ConditionalCache::reject($this, $key, $value, $onInvalid, 'flexibleWhen');

                        return;
                    }

                    $this->putMany([
                        $key => $value,
                        $createdKey => Carbon::now()->getTimestamp(),
                    ], $ttl[1]);
                });
            };

            defer($refresh, "illuminate:cache:flexible:{$this->itemKey($key)}", $alwaysDefer);

            return $value;
        };
    }
}
