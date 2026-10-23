<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Pointcut;

#[Aspect]
final class BadPointcutParamsAspect
{
    #[Pointcut('execution(* *(..))')]
    public function bad(int $x): void
    {
    }
}
