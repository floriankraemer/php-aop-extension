<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class MethodMarked
{
    #[SubMark]
    public function tagged(): void
    {
    }

    public function untagged(): void
    {
    }
}
