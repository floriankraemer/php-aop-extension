<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Log;

#[Aspect]
final class ServiceAspect
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::*(..)) && service(billing.*)')]
    public function note(): void
    {
        Log::add('service-advice');
    }
}
