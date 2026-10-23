<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class SubMark extends Mark
{
}
