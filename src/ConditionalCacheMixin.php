<?php

namespace ReneRoscher\ConditionalCache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\Repository;

/**
 * Macros mixed into \Illuminate\Cache\Repository.
 *
 * Every returned closure is bound to the cache repository instance it is called on
 * (including tagged caches), so `$this` refers to that repository.
 *
 * @mixin Repository
 */
class ConditionalCacheMixin
{
    /**
     * Get an item from the cache, or execute the callback and store the result
     * only if it passes the validator.
     *
     * Signature: rememberWhen($key, $ttl, $callback, $validator = null, $onInvalid = null, $default = null, $retryAfter = null, $lock = null)
     */
    public function rememberWhen(): Closure
    {
        return function (mixed $key, Closure|DateTimeInterface|DateInterval|int|null $ttl, Closure $callback, callable|string|null $validator = null, ?callable $onInvalid = null, mixed $default = null, DateTimeInterface|DateInterval|int|null $retryAfter = null, ?int $lock = null) {
            /** @var Repository $this */
            $key = ConditionalCache::key($key);

            return ConditionalCache::remember(
                $this, $this->getName(), 'rememberWhen', $key, $callback, $validator, $onInvalid, $default, $retryAfter, $lock,
                fn (string $key): string => $this->itemKey($key),
                fn ($value) => $this->put($key, $value, ConditionalCache::ttl($ttl, $value)),
            );
        };
    }

    /**
     * Get an item from the cache, or execute the callback and store the result
     * forever, but only if it passes the validator.
     *
     * Signature: rememberForeverWhen($key, $callback, $validator = null, $onInvalid = null, $default = null, $retryAfter = null, $lock = null)
     */
    public function rememberForeverWhen(): Closure
    {
        return function (mixed $key, Closure $callback, callable|string|null $validator = null, ?callable $onInvalid = null, mixed $default = null, DateTimeInterface|DateInterval|int|null $retryAfter = null, ?int $lock = null) {
            /** @var Repository $this */
            $key = ConditionalCache::key($key);

            return ConditionalCache::remember(
                $this, $this->getName(), 'rememberForeverWhen', $key, $callback, $validator, $onInvalid, $default, $retryAfter, $lock,
                fn (string $key): string => $this->itemKey($key),
                fn ($value) => $this->forever($key, $value),
            );
        };
    }

    /**
     * Stale-while-revalidate (like Cache::flexible), but values are only written
     * when they pass the validator. A rejected refresh keeps the previous value.
     *
     * Signature: flexibleWhen($key, [$fresh, $stale], $callback, $validator = null, $onInvalid = null, $default = null, $lock = null, $alwaysDefer = false, $retryAfter = null)
     */
    public function flexibleWhen(): Closure
    {
        return function (mixed $key, array $ttl, callable $callback, callable|string|null $validator = null, ?callable $onInvalid = null, mixed $default = null, ?array $lock = null, bool $alwaysDefer = false, DateTimeInterface|DateInterval|int|null $retryAfter = null) {
            /** @var Repository $this */
            $key = ConditionalCache::key($key);

            return ConditionalCache::flexible(
                $this, $this->getName(), $key, $ttl, $callback, $validator, $onInvalid, $default, $lock, $alwaysDefer, $retryAfter,
                fn (string $key): string => $this->itemKey($key),
                fn (DateTimeInterface|DateInterval|int $ttl): int => $this->getSeconds($ttl),
            );
        };
    }
}
