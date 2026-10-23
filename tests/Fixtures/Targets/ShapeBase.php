<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

abstract class ShapeBase
{
    abstract public function sides(): int;
}
