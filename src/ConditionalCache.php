<?php

namespace ReneRoscher\ConditionalCache;

use Illuminate\Contracts\Cache\Repository;
use ReneRoscher\ConditionalCache\Events\CacheValueRejected;

/**
 * @internal Shared logic used by the cache macros.
 */
final class ConditionalCache
{
    /**
     * Determine whether the given value may be written to the cache.
     *
     * Without a validator, only "filled" values are cached (see the filled() helper).
     */
    public static function passes(mixed $value, ?callable $validator): bool
    {
        return (bool) ($validator === null ? filled($value) : $validator($value));
    }

    /**
     * Handle a value that failed validation and resolve the value to return.
     *
     * The "rejected" event is dispatched and the $onInvalid callback is invoked with
     * the value and the key. If the callback returns something other than null, that
     * is returned instead of the rejected value. Neither value is ever cached.
     */
    public static function reject(Repository $cache, string $key, mixed $value, ?callable $onInvalid, string $method): mixed
    {
        if (method_exists($cache, 'getEventDispatcher')) {
            $cache->getEventDispatcher()?->dispatch(new CacheValueRejected(
                method_exists($cache, 'getName') ? $cache->getName() : null,
                $key,
                $value,
                $method,
            ));
        }

        if ($onInvalid === null) {
            return $value;
        }

        return $onInvalid($value, $key) ?? $value;
    }
}
