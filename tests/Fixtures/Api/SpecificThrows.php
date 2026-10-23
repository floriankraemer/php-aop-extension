<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advice\Api\ThrowsAdviceInterface;
use AOP\JoinPoint\JoinPointInterface;

final class SpecificThrows implements ThrowsAdviceInterface
{
    /** @var list<string> */
    public array $handled = [];

    public function afterThrowing(\InvalidArgumentException $e): void
    {
        $this->handled[] = 'invalid-argument';
    }

    public function afterThrowingLogic(JoinPointInterface $joinPoint, \LogicException $e): void
    {
        $this->handled[] = 'logic:'.$joinPoint->getSignature()->getName();
    }

    public function afterThrowingRuntime(\RuntimeException|\UnexpectedValueException $e): void
    {
        $this->handled[] = 'runtime-or-unexpected';
    }

    public function afterThrowingAny(\Throwable $e): void
    {
        $this->handled[] = 'throwable';
    }

    public function helper(): void
    {
    }
}
