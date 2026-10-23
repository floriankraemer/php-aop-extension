<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\Tests\Fixtures\Hierarchy\Marker;
use AOP\Tests\Fixtures\Targets\PropertyTag;

/**
 * Code that contains call sites, field accesses and handlers.
 */
#[Marker('caller')]
class Caller
{
    public function __construct()
    {
    }

    public function run(): void
    {
    }

    #[PropertyTag('tagged')]
    public function tagged(): void
    {
    }

    protected function guarded(): void
    {
    }

    public static function staticRun(): void
    {
    }
}
