<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface ShapeFactoryInterface
{
    /** Declares a parent class of the implementation as the return type. */
    public function scaled(int $factor): ShapeBase;

    public function sides(): int;
}
