<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;

#[Aspect]
final class CrossReferenceAspect
{
    #[Before('AOP\Tests\Fixtures\Aspect\NamedPointcutAspect::greets()')]
    public function viaOtherAspect(JoinPointInterface $jp): void
    {
    }

    #[Before('missing()')]
    public function viaUnknown(JoinPointInterface $jp): void
    {
    }
}
