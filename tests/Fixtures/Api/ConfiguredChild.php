<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Tests\Fixtures\Log;

final class ConfiguredChild extends ConfiguredService
{
    public function __construct()
    {
        Log::add('child-before-parent');
        parent::__construct('child');
        Log::add('child-after-parent');
    }
}
