<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

use AOP\JoinPoint\JoinPointInterface;
use AOP\Tests\Fixtures\Log;

/** An aspect without attributes, wired through Weaver::fromConfig(). */
final class PlainConfigAspect
{
    public function log(JoinPointInterface $jp): void
    {
        Log::add('config:'.$jp->getSignature()->getName());
    }
}
