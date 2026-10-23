<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

final class FinalWorkTarget implements WorkInterface
{
    public function work(string $input): string
    {
        return 'worked '.$input;
    }
}
