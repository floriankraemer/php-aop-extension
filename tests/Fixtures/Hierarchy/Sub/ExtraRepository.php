<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Hierarchy\Sub;

use AOP\Tests\Fixtures\Hierarchy\UserRepository;

class ExtraRepository extends UserRepository
{
    public function extra(): void
    {
    }
}
