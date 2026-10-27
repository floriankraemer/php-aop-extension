<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Log;

/**
 * Advises every advice execution, including its own.
 */
#[Aspect]
final class WatchAdviceAspect
{
    #[Before('adviceexecution()')]
    public function watch(): void
    {
        Log::add('watch');
    }
}
