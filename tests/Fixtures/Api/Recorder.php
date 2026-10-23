<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advice\Api\AfterReturningAdviceInterface;
use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Advice\Api\MethodInterceptorInterface;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;

/**
 * All three advice kinds in one object, writing to a list.
 */
final class Recorder implements MethodBeforeAdviceInterface, AfterReturningAdviceInterface, MethodInterceptorInterface
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly string $name = 'r')
    {
    }

    public function before(JoinPointInterface $joinPoint): void
    {
        $this->events[] = $this->name.':before:'.$joinPoint->getSignature()->getName();
    }

    public function afterReturning(mixed $returnValue, JoinPointInterface $joinPoint): void
    {
        $this->events[] = $this->name.':returned:'.var_export($returnValue, true);
    }

    public function invoke(ProceedingJoinPointInterface $invocation): mixed
    {
        $this->events[] = $this->name.':in';
        $result = $invocation->proceed();
        $this->events[] = $this->name.':out';

        return $result;
    }
}
