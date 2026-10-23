<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface NarrowableInterface
{
    public function get(): ?object;
}
