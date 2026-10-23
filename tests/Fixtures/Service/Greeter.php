<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Service;

use AOP\Tests\Fixtures\Log;

final class Greeter
{
    public function greet(string $name): string
    {
        Log::add('target');

        return 'Hello '.$name;
    }

    public function fail(): never
    {
        Log::add('target');

        throw new \RuntimeException('boom');
    }
}
