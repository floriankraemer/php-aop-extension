<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advisor\Advisor;
use AOP\Pointcut\PointcutInterface;

/**
 * An advice object plus the pointcut deciding where it applies (Spring's PointcutAdvisor).
 * `toAdvisor()` produces the `AOP\Advisor\Advisor` the Weaver registers.
 */
interface PointcutAdvisorInterface
{
    public function getId(): string;

    public function getOrder(): int;

    public function getPointcut(): PointcutInterface;

    public function getAdvice(): object;

    public function toAdvisor(): Advisor;
}
