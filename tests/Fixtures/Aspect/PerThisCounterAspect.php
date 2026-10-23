<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Aspect\AspectScope;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;

#[Aspect(scope: AspectScope::PER_THIS)]
final class PerThisCounterAspect
{
    public int $calls = 0;

    #[Before('execution(* AOP\Tests\Fixtures\Targets\ReaderRepo::find(..))')]
    public function count(): void
    {
        ++$this->calls;
    }
}
