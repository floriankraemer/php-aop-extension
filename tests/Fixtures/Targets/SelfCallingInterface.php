<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface SelfCallingInterface
{
    public function outer(): string;

    public function inner(): string;
}
