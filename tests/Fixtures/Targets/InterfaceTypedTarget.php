<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Attribute\Proxyable;
use AOP\Proxy\ProxyStrategy;
use AOP\Tests\Fixtures\Log;

#[Proxyable(ProxyStrategy::INTERFACE)]
class InterfaceTypedTarget implements ShoutInterface
{
    public function shout(string $text): string
    {
        Log::add('shout');

        return strtoupper($text).'!';
    }
}
