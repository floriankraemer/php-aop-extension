<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Attribute\Configurable;
use AOP\Tests\Fixtures\Log;

#[Configurable(autowire: true, preConstruction: true)]
class EarlyConfiguredService
{
    public ?string $injected = null;

    public function __construct()
    {
        Log::add('ctor:'.($this->injected ?? 'null'));
    }
}
