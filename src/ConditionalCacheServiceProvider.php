<?php

namespace ReneRoscher\ConditionalCache;

use Illuminate\Cache\Repository;
use Illuminate\Support\ServiceProvider;

class ConditionalCacheServiceProvider extends ServiceProvider
{
    /**
     * Register the conditional cache macros.
     */
    public function register(): void
    {
        Repository::mixin(new ConditionalCacheMixin, replace: false);
    }
}
