<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\JoinPoint\JoinPointInterface;

/**
 * Implemented by the aspect instance resolver of the native runtime: lets `AdviceInvoker` run an advice method
 * inside an `advice-execution` join point.
 */
interface AdviceExecutionWrapper
{
    /**
     * @param class-string                              $aspectClass
     * @param array<int|string, mixed>                  $values      the argument values bound for the advice method
     * @param \Closure(array<int|string, mixed>): mixed $call        invokes the advice method with (possibly replaced) values
     */
    public function wrapAdvice(JoinPointInterface $joinPoint, string $aspectClass, object $aspect, string $method, array $values, \Closure $call): mixed;
}
