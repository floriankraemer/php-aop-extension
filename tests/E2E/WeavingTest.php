<?php

declare(strict_types=1);

namespace AOP\Tests\E2E;

use AOP\Exception\AdviceReturnException;
use AOP\Exception\DeclareErrorException;
use AopE2E\BumpAspect;
use AopE2E\Driver;
use AopE2E\FinalService;
use AopE2E\ForbidAspect;
use AopE2E\Journal;
use AopE2E\JournalAspect;
use AopE2E\PerTargetAspect;
use AopE2E\WrongTypeAspect;
use PHPUnit\Framework\Attributes\Test;

final class WeavingTest extends EngineTestCase
{
    #[Test]
    public function privateStaticFinalAndProtectedMethodsAndSelfInvocationAreAdvised(): void
    {
        $this->weave([new JournalAspect()]);
        $driver = new Driver();
        $service = $driver->service();
        Journal::reset();

        self::assertSame('secret:x+counted+sealed', $driver->pub($service, 'x'));

        // pub() calls the private secret(), the static counted() and the final sealed() on itself.
        self::assertSame(['>pub', '>secret', '>counted', '>sealed'], Journal::$entries);
    }

    #[Test]
    public function protectedMethodsAreAdvised(): void
    {
        $this->weave([new JournalAspect()]);
        $driver = new Driver();
        $service = $driver->service();
        Journal::reset();

        self::assertSame('guarded', $driver->viaGuarded($service));
        self::assertSame(['>viaGuarded', '>guarded'], Journal::$entries);
    }

    #[Test]
    public function constructorsAreAdvised(): void
    {
        $this->weave([new JournalAspect()]);

        $service = (new Driver())->service();

        self::assertSame('made', $service->name);
        self::assertSame(['>__construct', 'ctor'], Journal::$entries);
    }

    #[Test]
    public function finalClassesAreAdvisedWithoutAnInterface(): void
    {
        $this->weave([new JournalAspect()]);

        self::assertSame('hello (advised)', (new Driver())->final(new FinalService()));
    }

    #[Test]
    public function functionsAreAdvisedAndMayReplaceTheirArguments(): void
    {
        $this->weave([new JournalAspect()]);

        self::assertSame('HI?!', (new Driver())->shout('hi'));
    }

    #[Test]
    public function internalFunctionsAreAdvised(): void
    {
        $this->weave([new JournalAspect()]);

        self::assertSame('abab', (new Driver())->repeat('ab', 2));
        self::assertSame(['str_repeat'], Journal::$entries);
    }

    #[Test]
    public function variadicArgumentsAreForwarded(): void
    {
        $this->weave([new JournalAspect()]);
        $driver = new Driver();
        $service = $driver->service();

        self::assertSame('a-b', $driver->join($service));
    }

    #[Test]
    public function byReferenceWriteThroughSurvivesReplacedArguments(): void
    {
        $this->weave([new BumpAspect()]);
        $driver = new Driver();

        self::assertSame(11, $driver->bump($driver->service()));
    }

    #[Test]
    public function aroundResultsAreTypeChecked(): void
    {
        $this->weave([new WrongTypeAspect()]);
        $driver = new Driver();

        $this->expectException(AdviceReturnException::class);
        $driver->count($driver->service());
    }

    #[Test]
    public function disablingStopsTheWeaving(): void
    {
        $weaver = $this->weave([new JournalAspect()]);
        $driver = new Driver();
        $weaver->disable();
        Journal::reset();

        $driver->sealed($driver->service());

        self::assertSame(['ctor'], Journal::$entries);
    }

    #[Test]
    public function perTargetAspectsKeepStatePerObject(): void
    {
        $this->weave([new PerTargetAspect()]);
        $driver = new Driver();
        $first = $driver->service();
        $second = $driver->service();

        self::assertSame('sealed#1sealed#2', $driver->sealedTwice($first));
        self::assertSame('sealed#1sealed#2', $driver->sealedTwice($second));
    }

    #[Test]
    public function declareErrorFailsTheAdvisedCall(): void
    {
        $this->weave([new ForbidAspect()]);

        $this->expectException(DeclareErrorException::class);
        (new Driver())->final(new FinalService());
    }
}
