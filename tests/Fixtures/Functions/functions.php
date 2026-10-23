<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Functions;

function fmt(string $value): string
{
    return '<'.$value.'>';
}

function noop(): void
{
}

#[\AOP\Tests\Fixtures\Targets\PropertyTag('tagged')]
function tagged(): void
{
}
