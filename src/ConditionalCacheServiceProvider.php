<?php

namespace ReneRoscher\ConditionalCache;

use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use ReneRoscher\ConditionalCache\Listeners\ForgetConditionalCacheEntries;

class ConditionalCacheServiceProvider extends ServiceProvider
{
    /**
     * Register the conditional cache macros.
     */
    public function register(): void
    {
        Repository::mixin(new ConditionalCacheMixin, replace: false);
    }

    /**
     * Keep Cache::forget() in sync with the package's bookkeeping keys.
     */
    public function boot(): void
    {
        $this->app->make(Dispatcher::class)->listen(ForgettingKey::class, ForgetConditionalCacheEntries::class);
    }
}
