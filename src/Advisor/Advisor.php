<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Advice\AdviceDefinition;
use AOP\Pointcut\PointcutInterface;

final readonly class Advisor
{
    /**
     * @param string $id AspectClass::method
     */
    public function __construct(
        public string $id,
        public AdviceDefinition $advice,
        public int $order,
        public PointcutInterface $pointcut,
    ) {
    }
}
