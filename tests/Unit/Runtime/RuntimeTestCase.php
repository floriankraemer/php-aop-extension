<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Tests\Fixtures\Log;
use AOP\Tests\Support\FakeEngine;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../Fixtures/Runtime/functions.php';

abstract class RuntimeTestCase extends TestCase
{
    protected FakeEngine $engine;

    protected function setUp(): void
    {
        Log::reset();
        $this->engine = new FakeEngine();
    }
}
