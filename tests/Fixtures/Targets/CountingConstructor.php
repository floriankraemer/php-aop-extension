<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

class CountingConstructor
{
    public static int $constructed = 0;

    public function __construct(private readonly string $prefix, private int $calls = 0)
    {
        ++self::$constructed;
    }

    public function work(string $input): string
    {
        Log::add('work');
        ++$this->calls;

        return $this->prefix.$input.$this->calls;
    }

    public function selfCall(string $input): string
    {
        return $this->work($input);
    }
}
