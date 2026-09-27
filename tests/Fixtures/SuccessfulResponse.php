<?php

namespace ReneRoscher\ConditionalCache\Tests\Fixtures;

use Illuminate\Contracts\Config\Repository as Config;

class SuccessfulResponse
{
    public function __construct(private Config $config) {}

    public function __invoke(mixed $value): bool
    {
        return ($value[$this->config->get('api.success_key', 'success')] ?? false) === true;
    }
}
