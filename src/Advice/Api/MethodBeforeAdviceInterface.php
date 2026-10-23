<?php

declare(strict_types=1);

namespace AOP\Advice\Api;

use AOP\JoinPoint\JoinPointInterface;

interface MethodBeforeAdviceInterface extends BeforeAdviceInterface
{
    /**
     * Runs before the advised method. Throwing prevents the call (and every later advice).
     */
    public function before(JoinPointInterface $joinPoint): void;
}
