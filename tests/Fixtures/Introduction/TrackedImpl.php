<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

final class TrackedImpl implements Tracked
{
    public function track(): string
    {
        return 'tracked';
    }
}
