<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

class SubCallee extends Callee
{
    public function __construct(int $fixed = 2, public string $extra = '')
    {
        parent::__construct($fixed);
    }
}
