<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

function shout(string $text, string $suffix = '!'): string
{
    return strtoupper($text).$suffix;
}
