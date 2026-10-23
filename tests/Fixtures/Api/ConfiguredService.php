<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Attribute\Configurable;
use AOP\Tests\Fixtures\Log;

#[Configurable(service: 'configured')]
class ConfiguredService
{
    public ?string $injected = null;

    public function __construct(public string $name = 'n')
    {
        Log::add('ctor:'.($this->injected ?? 'null'));
    }
}
