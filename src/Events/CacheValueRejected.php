<?php

namespace ReneRoscher\ConditionalCache\Events;

class CacheValueRejected
{
    /**
     * Create a new event instance.
     *
     * @param  string|null  $storeName  The name of the cache store.
     * @param  string  $key  The cache key the value was meant for.
     * @param  mixed  $value  The value that failed validation.
     * @param  string  $method  The macro that rejected the value (e.g. "rememberWhen").
     */
    public function __construct(
        public readonly ?string $storeName,
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $method,
    ) {}
}
