<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class Audited
{
}
