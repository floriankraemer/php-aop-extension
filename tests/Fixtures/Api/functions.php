<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

#[Mark]
function marked_function(): int
{
    return 1;
}

function get_value(): int
{
    return 2;
}
