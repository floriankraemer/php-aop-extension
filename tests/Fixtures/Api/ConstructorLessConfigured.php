<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Attribute\Configurable;

#[Configurable]
final class ConstructorLessConfigured
{
    public ?string $injected = null;
}
