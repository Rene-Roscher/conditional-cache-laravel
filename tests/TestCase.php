<?php

namespace ReneRoscher\ConditionalCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ReneRoscher\ConditionalCache\ConditionalCacheServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ConditionalCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }
}
