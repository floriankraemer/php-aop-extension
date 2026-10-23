<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Tests\Fixtures\Log;

final class FinalPooled
{
    public function __construct(private readonly string $id = 'real')
    {
        Log::add('ctor:'.$id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function __destruct()
    {
        Log::add('dtor');
    }
}
