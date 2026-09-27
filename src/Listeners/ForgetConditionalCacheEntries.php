<?php

namespace ReneRoscher\ConditionalCache\Listeners;

use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use InvalidArgumentException;
use ReneRoscher\ConditionalCache\ConditionalCache;

/**
 * Makes Cache::forget($key) also forget the "retryAfter" marker of that key.
 *
 * Listens to ForgettingKey (not KeyForgotten), because after a rejected value the key
 * itself doesn't exist, so forgetting it "fails" while the marker is still there.
 */
class ForgetConditionalCacheEntries
{
    public function __construct(private Factory $cache) {}

    public function handle(ForgettingKey $event): void
    {
        $key = (string) $event->key;

        // Our own markers (and a store without a name) have nothing to clean up.
        if ($event->storeName === null || str_starts_with($key, ConditionalCache::RETRY_KEY_PREFIX)) {
            return;
        }

        try {
            $repository = $this->cache->store($event->storeName);
        } catch (InvalidArgumentException) {
            // A repository built by hand with a store name that isn't configured. This cleanup
            // is best effort and must never break the forget() call that triggered it.
            return;
        }

        if ($event->tags !== [] && $repository instanceof Repository && $repository->supportsTags()) {
            $repository = $repository->tags($event->tags);
        }

        $repository->forget(ConditionalCache::RETRY_KEY_PREFIX.$key);
    }
}
