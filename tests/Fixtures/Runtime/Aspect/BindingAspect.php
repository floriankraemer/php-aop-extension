<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Log;

#[Aspect]
final class BindingAspect
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..)) && args(name)')]
    public function name(string $name): void
    {
        Log::add('name='.$name);
    }
}
