<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

/**
 * The parent interface is listed first, so it is the first declaration of get().
 */
final class Narrowed implements NarrowableInterface, NarrowedInterface
{
    public function get(): object
    {
        Log::add('get');

        return new \stdClass();
    }
}
