<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

/**
 * No properties at all: a native lazy object of such a class is initialized immediately unless the proxy adds one.
 */
class Stateless
{
    public function __construct()
    {
        Log::add('stateless constructed');
    }

    public function work(string $input): string
    {
        Log::add('work');

        return strtoupper($input);
    }
}
