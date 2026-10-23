<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\Attribute\Pointcut;
use AOP\Attribute\Proxyable;
use AOP\Proxy\ProxyStrategy;

#[Aspect]
#[Order(10)]
#[Proxyable(ProxyStrategy::INTERFACE)]
final class AttributeFixtureAspect
{
    #[Pointcut('execution(* App\Service\*Service::*(..))')]
    public function servicePointcut(): void
    {
    }

    #[Before('servicePointcut()')]
    public function logBefore(): void
    {
    }

    #[After('servicePointcut()')]
    public function logAfter(): void
    {
    }

    #[Around('servicePointcut()')]
    public function wrapAround(): void
    {
    }

    #[AfterReturning('servicePointcut()', returning: 'result')]
    public function logReturn(): void
    {
    }

    #[AfterReturning('servicePointcut()')]
    public function logReturnUnbound(): void
    {
    }

    #[AfterThrowing('servicePointcut()', throwing: 'error')]
    public function logThrow(): void
    {
    }
}
