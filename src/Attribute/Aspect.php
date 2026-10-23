<?php

declare(strict_types=1);

namespace AOP\Attribute;

use AOP\Aspect\AspectScope;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Aspect
{
    /**
     * @param string|null $pointcut the AspectJ `perthis(..)`/`pertarget(..)`/`percflow(..)`/`percflowbelow(..)` pointcut
     *                              expression, or for `pertypewithin` a type pattern. Required for percflow, percflowbelow and
     *                              pertypewithin; for perthis and pertarget it restricts the join points the aspect advises
     */
    public function __construct(
        public readonly AspectScope $scope = AspectScope::SINGLETON,
        public readonly ?string $pointcut = null,
    ) {
    }
}
