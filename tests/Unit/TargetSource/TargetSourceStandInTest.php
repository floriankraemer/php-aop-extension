<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\TargetSource;

use AOP\Advisor\Advisor;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\JoinPoint\JoinPointKind;
use AOP\TargetSource\HotSwappableTargetSource;
use AOP\TargetSource\PoolExhaustedException;
use AOP\TargetSource\PoolingTargetSource;
use AOP\TargetSource\PrototypeTargetSource;
use AOP\TargetSource\SingletonTargetSource;
use AOP\TargetSource\TargetSourceException;
use AOP\TargetSource\TargetSourceInterface;
use AOP\TargetSource\TargetSourceStandIn;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\BeforeOnly;
use AOP\Tests\Fixtures\Api\FinalPooled;
use AOP\Tests\Fixtures\Api\Identified;
use AOP\Tests\Fixtures\Api\IdentifiedService;
use AOP\Tests\Fixtures\Api\Pooled;
use AOP\Tests\Fixtures\Api\Run;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Log;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetSourceStandInTest extends TestCase
{
    protected function setUp(): void
    {
        Pooled::resetCounter();
        Log::reset();
    }

    #[Test]
    public function createsAStandInWithoutRunningItsConstructor(): void
    {
        $source = new SingletonTargetSource(new FinalPooled('real'));
        Log::reset();

        [$standIn, $advisor] = TargetSourceStandIn::create(FinalPooled::class, $source);

        self::assertInstanceOf(FinalPooled::class, $standIn);
        self::assertInstanceOf(Advisor::class, $advisor);
        self::assertSame([], Log::$entries, 'the constructor of a final class did not run');
        self::assertNull($advisor->advice);
        self::assertNotNull($advisor->interceptor);
        self::assertStringStartsWith('~~target-source:'.FinalPooled::class.'#', $advisor->id);
    }

    #[Test]
    public function reroutesMethodExecutionsOfTheStandInToTheTarget(): void
    {
        $target = new FinalPooled('the-target');
        [$standIn, $advisor] = TargetSourceStandIn::create(FinalPooled::class, new SingletonTargetSource($target));

        $result = Run::call([$advisor], $standIn, 'id');

        self::assertSame('the-target', $result);
    }

    #[Test]
    public function passesArgumentsAndGetsAFreshTargetPerCall(): void
    {
        $source = new PrototypeTargetSource(Pooled::class, static fn (): Pooled => new Pooled());
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, $source);

        self::assertSame('a#1', Run::call([$advisor], $standIn, 'work', ['a']));
        self::assertSame('b#2', Run::call([$advisor], $standIn, 'work', ['what' => 'b']));
        self::assertSame(2, $source->getCreatedCount());
    }

    #[Test]
    public function releasesTheTargetAfterTheCallAlsoWhenItThrows(): void
    {
        $source = new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 1);
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, $source);

        Run::call([$advisor], $standIn, 'work');
        self::assertSame(0, $source->stats()->active);

        try {
            Run::call([$advisor], $standIn, 'boom');
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertSame('boom 1', $e->getMessage());
        }

        self::assertSame(0, $source->stats()->active);
        self::assertSame(1, $source->stats()->created, 'the single pooled target is reused');
        self::assertSame('x#1', Run::call([$advisor], $standIn, 'work'));
    }

    #[Test]
    public function exhaustionOfTheSourceSurfacesToTheCaller(): void
    {
        $source = new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 1);
        $held = $source->getTarget();
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, $source);

        try {
            Run::call([$advisor], $standIn, 'work');
            self::fail();
        } catch (PoolExhaustedException) {
        }

        $source->releaseTarget($held);
        self::assertSame('x#1', Run::call([$advisor], $standIn, 'work'));
    }

    #[Test]
    public function hotSwappingChangesWhereTheStandInRoutesTo(): void
    {
        $source = new HotSwappableTargetSource(new IdentifiedService('one'), IdentifiedService::class);
        [$standIn, $advisor] = TargetSourceStandIn::create(IdentifiedService::class, $source);

        self::assertSame('one', Run::call([$advisor], $standIn, 'id'));
        $source->swap(new IdentifiedService('two'));
        self::assertSame('two', Run::call([$advisor], $standIn, 'id'));
    }

    #[Test]
    public function aTargetReturningItselfYieldsTheStandIn(): void
    {
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, new SingletonTargetSource(new Pooled()));

        self::assertSame($standIn, Run::call([$advisor], $standIn, 'again'));
    }

    #[Test]
    public function theAdvisorOnlyConcernsItsOwnStandIn(): void
    {
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, new SingletonTargetSource(new Pooled()));
        $other = new Pooled();
        $otherStandIn = TargetSourceStandIn::create(Pooled::class, new SingletonTargetSource(new Pooled()))[0];

        // statically it applies to every instance of the class; the runtime identity check keeps the others out
        self::assertTrue($advisor->pointcut->isRuntime());
        self::assertSame('x#2', Run::call([$advisor], $other, 'work'), 'a real object runs its own code');
        try {
            Run::call([$advisor], $otherStandIn, 'work');
            self::fail('a different stand-in is not rerouted by this advisor, so its own (uninitialised) code runs');
        } catch (\Error $e) {
            self::assertStringContainsString('must not be accessed before initialization', $e->getMessage());
        }
        self::assertSame('x#1', Run::call([$advisor], $standIn, 'work'));
    }

    #[Test]
    public function staticMethodsAndOtherKindsAreNotRerouted(): void
    {
        [$standIn, $advisor] = TargetSourceStandIn::create(Account::class, new SingletonTargetSource(new Account()));
        $ctx = Jp::ctx(Account::class, 'create');
        $class = new \ReflectionClass(Account::class);

        self::assertFalse($advisor->pointcut->matchesMethod(new \ReflectionMethod(Account::class, 'create'), $ctx));
        self::assertTrue($advisor->pointcut->matchesMethod(new \ReflectionMethod(Account::class, 'getBalance'), Jp::ctx(Account::class, 'getBalance')));
        self::assertFalse($advisor->pointcut->matchesClass($class, Jp::ctx(Account::class, 'getBalance', JoinPointKind::METHOD_CALL)));
        self::assertFalse($advisor->pointcut->matchesClass(new \ReflectionClass(Pooled::class), Jp::ctx(Pooled::class, 'work')));
        self::assertFalse($advisor->pointcut->matchesClass(null, Jp::ctx(Account::class, 'getBalance')));
        self::assertSame([], $advisor->pointcut->bindings());
        self::assertFalse($advisor->pointcut->isCallerSensitive());
        self::assertSame(100, Run::call([$advisor], $standIn, 'getBalance'));
    }

    #[Test]
    public function otherAdvisorsWrapTheRerouting(): void
    {
        $target = new FinalPooled('t');
        [$standIn, $reroute] = TargetSourceStandIn::create(FinalPooled::class, new SingletonTargetSource($target));
        Log::reset();
        $before = (new DefaultPointcutAdvisor('execution(* **::*(..))', new BeforeOnly('user-advice')))->toAdvisor();

        $result = Run::call([$reroute, $before], $standIn, 'id');

        self::assertSame('t', $result);
        self::assertSame(['user-advice'], Log::$entries, 'the user advice ran, then the rerouting, whatever the registration order');
    }

    #[Test]
    public function theStandInPointcutDoesNotKeepTheStandInAlive(): void
    {
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, new SingletonTargetSource(new Pooled()));
        $reference = \WeakReference::create($standIn);
        unset($standIn);

        self::assertNull($reference->get());
        self::assertNotNull($advisor->interceptor);
    }

    #[Test]
    public function rejectsClassesThatCannotBeStandIns(): void
    {
        $source = new SingletonTargetSource(new Pooled());

        /** @var list<array{class-string, string}> $cases */
        $cases = [[Identified::class, 'not a class'], ['No\\Such\\Thing', 'not a class'], [\AOP\Tests\Fixtures\Hierarchy\AbstractRepository::class, 'abstract class']];
        foreach ($cases as [$class, $message]) {
            try {
                TargetSourceStandIn::create($class, $source);
                self::fail($class);
            } catch (TargetSourceException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    #[Test]
    public function rejectsASourceWhoseTargetsAreNotOfTheClass(): void
    {
        $this->expectException(TargetSourceException::class);
        $this->expectExceptionMessage('which is not a '.Account::class);

        TargetSourceStandIn::create(Account::class, new SingletonTargetSource(new Pooled()));
    }

    #[Test]
    public function aStandInMayBeASupertypeOfTheTargets(): void
    {
        $source = new SingletonTargetSource(new \AOP\Tests\Fixtures\Api\SavingsAccount());
        [$standIn, $advisor] = TargetSourceStandIn::create(Account::class, $source);

        self::assertSame(200, Run::call([$advisor], $standIn, 'getBalance'), 'the target class override runs');
    }

    #[Test]
    public function wrapsAnyTargetSource(): void
    {
        $source = new class implements TargetSourceInterface {
            /** @var list<string> */
            public array $calls = [];

            public function getTargetClass(): string
            {
                return Pooled::class;
            }

            public function isStatic(): bool
            {
                return false;
            }

            public function getTarget(): object
            {
                $this->calls[] = 'get';

                return new Pooled();
            }

            public function releaseTarget(object $target): void
            {
                $this->calls[] = 'release';
            }
        };
        [$standIn, $advisor] = TargetSourceStandIn::create(Pooled::class, $source);

        Run::call([$advisor], $standIn, 'work');

        self::assertSame(['get', 'release'], $source->calls);
    }
}
