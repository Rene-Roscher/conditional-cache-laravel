<?php

namespace ReneRoscher\ConditionalCache;

use BackedEnum;
use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ReneRoscher\ConditionalCache\Events\CacheValueRejected;
use UnitEnum;

use function Illuminate\Support\defer;

/**
 * @internal The implementation behind the cache macros. Use the macros instead.
 */
final class ConditionalCache
{
    /**
     * The key prefix used by Cache::flexible() to track when a value was last refreshed.
     */
    public const FLEXIBLE_CREATED_KEY_PREFIX = 'illuminate:cache:flexible:created:';

    /**
     * The key prefix used to remember a rejected value during the "retryAfter" window.
     */
    public const RETRY_KEY_PREFIX = 'conditional-cache:retry:';

    /**
     * The key prefix of the lock used by the "lock" option.
     */
    public const LOCK_KEY_PREFIX = 'conditional-cache:lock:';

    /**
     * Normalize a cache key, resolving backed enums to their value.
     */
    public static function key(mixed $key): string
    {
        // Same semantics as Laravel's enum_value(), which isn't autoloaded in early Laravel 11 releases.
        if ($key instanceof BackedEnum) {
            $key = $key->value;
        } elseif ($key instanceof UnitEnum) {
            $key = $key->name;
        }

        if (! is_string($key) && ! is_int($key)) {
            throw new InvalidArgumentException('The cache key must be a string, an integer or a backed enum.');
        }

        return (string) $key;
    }

    /**
     * Get an item from the cache, or resolve it and store it via $store if it passes validation.
     *
     * With $lock (seconds), only one process computes a missing value at a time; the others
     * wait up to that long for it and compute it themselves if the lock can't be acquired.
     *
     * @param  Closure(string): string  $itemKey  Resolves the store-level key (tags aware).
     * @param  Closure(mixed): mixed  $store
     */
    public static function remember(
        Repository $cache,
        ?string $storeName,
        string $method,
        string $key,
        Closure $callback,
        callable|string|null $validator,
        ?callable $onInvalid,
        mixed $default,
        DateTimeInterface|DateInterval|int|null $retryAfter,
        ?int $lock,
        Closure $itemKey,
        Closure $store,
    ): mixed {
        self::ensureDefaultForRetry($retryAfter, $default, $method);

        [$found, $value] = self::lookup($cache, $key, $retryAfter);

        if ($found) {
            return $value;
        }

        $resolve = function () use ($cache, $storeName, $method, $key, $callback, $validator, $onInvalid, $default, $retryAfter, $store) {
            $value = $callback();

            if (! self::passes($value, $validator)) {
                return self::reject($cache, $storeName, $method, $key, $value, $onInvalid, $default, $retryAfter);
            }

            $store($value);

            return $value;
        };

        if ($lock === null) {
            return $resolve();
        }

        if ($lock < 1) {
            throw new InvalidArgumentException("{$method}() expects the lock duration in seconds (at least 1).");
        }

        $locks = $cache->getStore();

        if (! $locks instanceof LockProvider) {
            throw new BadMethodCallException("{$method}() with a lock requires a cache store that supports locks.");
        }

        try {
            return $locks->lock(self::LOCK_KEY_PREFIX.$itemKey($key), $lock)->block($lock, function () use ($cache, $key, $retryAfter, $resolve) {
                // Another process may have resolved the value while we were waiting.
                [$found, $value] = self::lookup($cache, $key, $retryAfter);

                return $found ? $value : $resolve();
            });
        } catch (LockTimeoutException) {
            return $resolve();
        }
    }

    /**
     * Look up a cached value, or the remembered result of a recent rejection.
     *
     * @return array{0: bool, 1: mixed} Whether something was found, and the value.
     */
    private static function lookup(Repository $cache, string $key, DateTimeInterface|DateInterval|int|null $retryAfter): array
    {
        if ($retryAfter === null) {
            $value = $cache->get($key);

            return [! is_null($value), $value];
        }

        [$value, $retry] = self::many($cache, [$key, self::RETRY_KEY_PREFIX.$key]);

        if (! is_null($value)) {
            return [true, $value];
        }

        if (is_array($retry) && array_key_exists('value', $retry)) {
            return [true, $retry['value']];
        }

        return [false, null];
    }

    /**
     * Require a default when retryAfter is used, so a rejected value is never stored.
     */
    private static function ensureDefaultForRetry(mixed $retryAfter, mixed $default, string $method): void
    {
        if ($retryAfter !== null && $default === null) {
            throw new InvalidArgumentException(
                "{$method}() with retryAfter requires a default, which is returned while waiting to retry. "
                .'Use default: fn ($value) => $value to get the rejected value back.'
            );
        }
    }

    /**
     * Stale-while-revalidate that only writes values passing validation.
     *
     * @param  array<mixed>  $ttl  [$fresh, $stale]
     * @param  array<mixed>|null  $lock  ['seconds' => int, 'owner' => string]
     * @param  Closure(string): string  $itemKey  Resolves the store-level key (tags aware).
     * @param  Closure(DateTimeInterface|DateInterval|int): int  $seconds  Converts a TTL to seconds.
     */
    public static function flexible(
        Repository $cache,
        ?string $storeName,
        string $key,
        array $ttl,
        callable $callback,
        callable|string|null $validator,
        ?callable $onInvalid,
        mixed $default,
        ?array $lock,
        bool $alwaysDefer,
        DateTimeInterface|DateInterval|int|null $retryAfter,
        Closure $itemKey,
        Closure $seconds,
    ): mixed {
        self::ensureDefaultForRetry($retryAfter, $default, 'flexibleWhen');

        [$fresh, $stale] = self::flexibleTtl($ttl);
        [$lockSeconds, $lockOwner] = self::lockOptions($lock);

        $createdKey = self::FLEXIBLE_CREATED_KEY_PREFIX.$key;
        $retryKey = self::RETRY_KEY_PREFIX.$key;

        [$value, $created, $retry] = self::many(
            $cache, $retryAfter === null ? [$key, $createdKey] : [$key, $createdKey, $retryKey],
        );

        if (is_null($value) || ! is_int($created)) {
            if (is_array($retry) && array_key_exists('value', $retry)) {
                return $retry['value'];
            }

            $value = value($callback);

            if (! self::passes($value, $validator)) {
                return self::reject($cache, $storeName, 'flexibleWhen', $key, $value, $onInvalid, $default, $retryAfter);
            }

            $cache->putMany([$key => $value, $createdKey => Carbon::now()->getTimestamp()], $stale);

            return $value;
        }

        // Still fresh, or a recent refresh was rejected and we are waiting to retry.
        if (($created + $seconds($fresh)) > Carbon::now()->getTimestamp() || ! is_null($retry)) {
            return $value;
        }

        $itemKey = $itemKey($key);

        $refresh = function () use ($cache, $storeName, $key, $itemKey, $createdKey, $stale, $callback, $validator, $onInvalid, $default, $lockSeconds, $lockOwner, $created, $retryAfter) {
            $store = $cache->getStore();

            if (! $store instanceof LockProvider) {
                throw new BadMethodCallException('flexibleWhen() requires a cache store that supports locks.');
            }

            $store->lock(
                "illuminate:cache:flexible:lock:{$itemKey}",
                $lockSeconds,
                $lockOwner,
            )->get(function () use ($cache, $storeName, $key, $createdKey, $stale, $callback, $validator, $onInvalid, $default, $created, $retryAfter) {
                if ($created !== $cache->get($createdKey)) {
                    return;
                }

                $value = value($callback);

                if (! self::passes($value, $validator)) {
                    self::reject($cache, $storeName, 'flexibleWhen', $key, $value, $onInvalid, $default, $retryAfter);

                    return;
                }

                $cache->putMany([$key => $value, $createdKey => Carbon::now()->getTimestamp()], $stale);
            });
        };

        defer($refresh, "illuminate:cache:flexible:{$itemKey}", $alwaysDefer);

        return $value;
    }

    /**
     * Determine whether the given value may be written to the cache.
     *
     * The validator may be a callable or the class name of an invokable class, which is
     * resolved from the container. Without a validator, only filled() values pass.
     */
    public static function passes(mixed $value, callable|string|null $validator): bool
    {
        if ($validator === null) {
            return filled($value);
        }

        if (! is_callable($validator)) {
            $validator = class_exists($validator) ? Container::getInstance()->make($validator) : null;

            if (! is_callable($validator)) {
                throw new InvalidArgumentException('The cache validator must be a callable or the class name of an invokable class.');
            }
        }

        return (bool) $validator($value);
    }

    /**
     * Handle a value that failed validation and resolve the value to return.
     *
     * Dispatches the "rejected" event and invokes $onInvalid with the value and the key
     * (its return value is ignored). Returns $default instead of the rejected value when
     * one is given; a Closure default is called with the value and the key, so it can
     * also return null. With $retryAfter, the result is remembered so the callback
     * isn't run again until the window has passed.
     */
    public static function reject(
        Repository $cache,
        ?string $storeName,
        string $method,
        string $key,
        mixed $value,
        ?callable $onInvalid,
        mixed $default = null,
        DateTimeInterface|DateInterval|int|null $retryAfter = null,
    ): mixed {
        $cache->getEventDispatcher()?->dispatch(new CacheValueRejected($storeName, $key, $value, $method));

        if ($onInvalid !== null) {
            $onInvalid($value, $key);
        }

        $result = $default instanceof Closure ? $default($value, $key) : ($default ?? $value);

        if ($retryAfter !== null) {
            $cache->put(self::RETRY_KEY_PREFIX.$key, ['value' => $result], $retryAfter);
        }

        return $result;
    }

    /**
     * Resolve the TTL for a value, calling the TTL closure with the value if needed.
     */
    public static function ttl(mixed $ttl, mixed $value): DateTimeInterface|DateInterval|int|null
    {
        if ($ttl instanceof Closure) {
            $ttl = $ttl($value);
        }

        if (! is_null($ttl) && ! is_int($ttl) && ! $ttl instanceof DateTimeInterface && ! $ttl instanceof DateInterval) {
            throw new InvalidArgumentException('The cache TTL must be an integer, a DateTimeInterface, a DateInterval or null.');
        }

        return $ttl;
    }

    /**
     * Validate the [$fresh, $stale] TTL pair used by flexibleWhen().
     *
     * @param  array<mixed>  $ttl
     * @return array{0: DateTimeInterface|DateInterval|int, 1: DateTimeInterface|DateInterval|int}
     */
    private static function flexibleTtl(array $ttl): array
    {
        $pair = [$ttl[0] ?? null, $ttl[1] ?? null];

        foreach ($pair as $item) {
            if (! is_int($item) && ! $item instanceof DateTimeInterface && ! $item instanceof DateInterval) {
                throw new InvalidArgumentException('flexibleWhen() expects the TTL as [$fresh, $stale], each an integer, a DateTimeInterface or a DateInterval.');
            }
        }

        /** @var array{0: DateTimeInterface|DateInterval|int, 1: DateTimeInterface|DateInterval|int} $pair */
        return $pair;
    }

    /**
     * Validate the lock options used by flexibleWhen().
     *
     * @param  array<mixed>|null  $lock
     * @return array{0: int, 1: string|null}
     */
    private static function lockOptions(?array $lock): array
    {
        $seconds = $lock['seconds'] ?? 0;
        $owner = $lock['owner'] ?? null;

        if (! is_int($seconds) || (! is_null($owner) && ! is_string($owner))) {
            throw new InvalidArgumentException('flexibleWhen() expects the lock as [\'seconds\' => int, \'owner\' => string].');
        }

        return [$seconds, $owner];
    }

    /**
     * Retrieve multiple items in one round trip, in the order of the given keys.
     *
     * @param  list<string>  $keys
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private static function many(Repository $cache, array $keys): array
    {
        $values = $cache->many($keys);

        return [$values[$keys[0]] ?? null, $values[$keys[1]] ?? null, isset($keys[2]) ? ($values[$keys[2]] ?? null) : null];
    }
}
