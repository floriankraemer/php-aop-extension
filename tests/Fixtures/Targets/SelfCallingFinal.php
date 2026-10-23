<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Runtime\AopContext;

final class SelfCallingFinal implements SelfCallingInterface
{
    public function outer(): string
    {
        $proxy = AopContext::currentProxy();
        \assert($proxy instanceof SelfCallingInterface);

        return 'outer>'.$proxy->inner();
    }

    public function inner(): string
    {
        return 'inner';
    }
}
