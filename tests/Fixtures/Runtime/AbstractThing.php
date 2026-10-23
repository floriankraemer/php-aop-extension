<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

abstract class AbstractThing
{
    abstract public function run(): string;

    public function concrete(): string
    {
        return 'concrete';
    }
}
