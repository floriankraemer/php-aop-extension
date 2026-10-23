<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Attribute\Proxyable;
use AOP\Proxy\ProxyStrategy;

#[Proxyable(ProxyStrategy::SUBCLASS)]
final class ForcedSubclassFinal implements ShoutInterface
{
    public function shout(string $text): string
    {
        return $text;
    }
}
