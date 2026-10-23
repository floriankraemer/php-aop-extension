<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface NarrowedInterface extends NarrowableInterface
{
    public function get(): object;
}
