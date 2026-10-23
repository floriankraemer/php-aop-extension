<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Aspect\AspectScope;
use AOP\Pointcut\PointcutInterface;

/**
 * Where the control flow of a percflow/percflowbelow aspect starts: join points matching `$pointcut`.
 */
final readonly class CflowEntry
{
    /**
     * @param class-string $aspectClass
     */
    public function __construct(
        public string $aspectClass,
        public AspectScope $scope,
        public PointcutInterface $pointcut,
    ) {
    }
}
