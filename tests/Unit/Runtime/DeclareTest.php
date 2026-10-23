<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Exception\DeclareErrorException;
use AOP\Tests\Fixtures\Runtime\Aspect\DeclareAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\DeclareErrorAspect;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class DeclareTest extends RuntimeTestCase
{
    #[Test]
    public function declareWarningRaisesOnceAtTheFirstMatchAndNamesTheJoinPoint(): void
    {
        Weaver::fromAspects([new DeclareAspect()], $this->engine)->enable();
        $service = new TargetService();
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message];

            return true;
        });
        try {
            self::assertSame('sealed', $this->engine->call($service, 'sealed'));
            $this->engine->call($service, 'sealed');
            $this->engine->call($service, 'pub', ['x']);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertSame(E_USER_WARNING, $warnings[0][0]);
        self::assertStringContainsString('do not use sealed()', $warnings[0][1]);
        self::assertStringContainsString('TargetService::sealed(..)', $warnings[0][1]);
        self::assertStringContainsString(DeclareAspect::class, $warnings[0][1]);
    }

    #[Test]
    public function declareErrorThrowsInsteadOfRunningTheJoinPoint(): void
    {
        Weaver::fromAspects([new DeclareErrorAspect()], $this->engine)->enable();

        try {
            $this->engine->call(new TargetService(), 'fail');
            self::fail('expected');
        } catch (DeclareErrorException $e) {
            self::assertStringContainsString('fail() is forbidden', $e->getMessage());
            self::assertStringContainsString('TargetService::fail(..)', $e->getMessage());
        }
    }

    #[Test]
    public function declareErrorKeepsThrowingOnEveryCall(): void
    {
        Weaver::fromAspects([new DeclareErrorAspect()], $this->engine)->enable();
        $service = new TargetService();

        foreach ([1, 2] as $_) {
            try {
                $this->engine->call($service, 'fail');
                self::fail('expected');
            } catch (DeclareErrorException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function unrelatedJoinPointsAreUntouched(): void
    {
        Weaver::fromAspects([new DeclareErrorAspect(), new DeclareAspect()], $this->engine)->enable();

        self::assertSame('pub:x', $this->engine->call(new TargetService(), 'pub', ['x']));
    }
}
