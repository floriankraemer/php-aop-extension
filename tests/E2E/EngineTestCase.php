<?php

declare(strict_types=1);

namespace AOP\Tests\E2E;

use AOP\Weaver;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Fixtures/Runtime/E2E/fixtures.php';

/**
 * Base class of the tests that need the compiled extension; they are skipped without it.
 * Run: php -d extension=ext/modules/aop.so vendor/bin/phpunit --testsuite e2e.
 */
abstract class EngineTestCase extends TestCase
{
    protected ?Weaver $weaver = null;

    protected function setUp(): void
    {
        if (!extension_loaded('aop')) {
            self::markTestSkipped('The aop extension is not loaded.');
        }
        \AopE2E\Journal::reset();
    }

    protected function tearDown(): void
    {
        $this->weaver?->disable();
        $this->weaver = null;
    }

    /**
     * @param list<object> $aspects
     */
    protected function weave(array $aspects): Weaver
    {
        $this->weaver = Weaver::fromAspects($aspects);
        $this->weaver->enable();

        return $this->weaver;
    }
}
