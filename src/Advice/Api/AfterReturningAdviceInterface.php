<?php

declare(strict_types=1);

namespace AOP\Advice\Api;

use AOP\JoinPoint\JoinPointInterface;

interface AfterReturningAdviceInterface extends AfterAdviceInterface
{
    /**
     * Runs after the method returned normally. It cannot change the return value; throwing makes the call fail.
     */
    public function afterReturning(mixed $returnValue, JoinPointInterface $joinPoint): void;
}
