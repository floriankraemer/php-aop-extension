<?php

declare(strict_types=1);

namespace AOP\Attribute;

use AOP\Proxy\ProxyStrategy;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Proxyable
{
    public function __construct(public readonly ProxyStrategy $strategy)
    {
    }
}
