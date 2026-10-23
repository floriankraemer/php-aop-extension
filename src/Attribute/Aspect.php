<?php

declare(strict_types=1);

namespace AOP\Attribute;

use AOP\Aspect\AspectScope;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Aspect
{
    public function __construct(public readonly AspectScope $scope = AspectScope::SINGLETON)
    {
    }
}
