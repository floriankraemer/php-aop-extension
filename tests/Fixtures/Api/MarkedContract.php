<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

#[SubMark]
interface MarkedContract
{
    #[Mark]
    public function contract(): int;
}
