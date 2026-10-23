<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Exception\BindingException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\CflowPointcut;
use AOP\Pointcut\ControlFlowPointcuts;
use AOP\Pointcut\EmptyControlFlow;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Conditions;
use AOP\Tests\Fixtures\Pointcut\DomainError;
use AOP\Tests\Fixtures\Pointcut\FakeControlFlow;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\StubJoinPoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CflowPointcutTest extends TestCase
{
    private const RUN = 'execution(* '.Caller::class.'::run(..))';
    private const FIND = 'execution(* '.Callee::class.'::find(..))';

    /**
     * Evaluates the pointcut for the last join point of the stack, which is the current one.
     *
     * @param list<StubJoinPoint> $stack
     * @param class-string        $class
     */
    private function context(array $stack, string $class = Callee::class, string $method = 'find'): RuntimeMatchContext
    {
        $current = $stack[count($stack) - 1];

        return Shadow::runtime($current, Shadow::method($class, $method), new FakeControlFlow($stack));
    }

    private function runExecution(?Caller $caller = null): StubJoinPoint
    {
        return StubJoinPoint::method($caller ?? new Caller(), 'run');
    }

    #[Test]
    public function cflowMatchesWhenAnOuterJoinPointMatches(): void
    {
        $pointcut = Shadow::compile('cflow('.self::RUN.')');

        self::assertInstanceOf(CflowPointcut::class, $pointcut);
        self::assertTrue($pointcut->matchesRuntime($this->context([$this->runExecution(), StubJoinPoint::method(new Callee(), 'find')])));
        self::assertFalse($pointcut->matchesRuntime($this->context([StubJoinPoint::method(new Callee(), 'find')])));
        self::assertFalse($pointcut->matchesRuntime($this->context([StubJoinPoint::method(new Callee(), 'find'), $this->runExecution()])), 'run() is entered after, not before, the current join point');
    }

    #[Test]
    public function cflowIncludesTheCurrentJoinPointAndCflowbelowDoesNot(): void
    {
        $cflow = Shadow::compile('cflow('.self::FIND.')');
        $below = Shadow::compile('cflowbelow('.self::FIND.')');
        $single = [StubJoinPoint::method(new Callee(), 'find')];
        $nested = [StubJoinPoint::method(new Callee(), 'find'), StubJoinPoint::method(new Callee(), 'find')];

        self::assertTrue($cflow->matchesRuntime($this->context($single)));
        self::assertFalse($below->matchesRuntime($this->context($single)));
        self::assertTrue($cflow->matchesRuntime($this->context($nested)));
        self::assertTrue($below->matchesRuntime($this->context($nested)), 'a recursive call is below the outer one');
    }

    #[Test]
    public function aMissingControlFlowNeverMatches(): void
    {
        $ctx = Shadow::runtime(StubJoinPoint::method(new Callee(), 'find'), Shadow::method(Callee::class, 'find'), new EmptyControlFlow());

        self::assertFalse(Shadow::compile('cflow('.self::FIND.')')->matchesRuntime($ctx));
        self::assertFalse(Shadow::compile('cflowbelow('.self::FIND.')')->matchesRuntime($ctx));
    }

    #[Test]
    public function theInnerPointcutIsMatchedStaticallyAndAtRuntime(): void
    {
        $pointcut = Shadow::compile('cflow('.self::FIND.' && args(int))');
        $withInt = StubJoinPoint::method(new Callee(), 'find', args: [7]);
        $withString = StubJoinPoint::method(new Callee(), 'find', args: ['x']);
        $current = StubJoinPoint::method(new Caller(), 'run');
        $ctx = fn (StubJoinPoint ...$stack): RuntimeMatchContext => Shadow::runtime($current, Shadow::method(Caller::class, 'run'), new FakeControlFlow(array_values([...$stack, $current])));

        self::assertTrue($pointcut->matchesRuntime($ctx($withInt)));
        self::assertFalse($pointcut->matchesRuntime($ctx($withString)), 'the runtime half of the inner pointcut fails');
        self::assertTrue($pointcut->matchesRuntime($ctx($withInt, $withString)), 'another entry matches');
        self::assertFalse(Shadow::compile('cflow(execution(* '.Callee::class.'::make(..)))')->matchesRuntime($ctx($withInt)), 'the static half fails');
    }

    #[Test]
    public function aCflowIsRuntimeAndNotCallerSensitiveItself(): void
    {
        $pointcut = Shadow::compile('cflow(call(* *(..)) && within('.Caller::class.'))');
        $ctx = Shadow::method(Callee::class, 'find');

        self::assertTrue($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive(), 'the entries are matched with their own recorded caller');
        self::assertTrue(Shadow::matches($pointcut, $ctx), 'the static half cannot decide');
        self::assertTrue(Shadow::matches(Shadow::compile('cflowbelow(execution(* *(..)))'), $ctx));
        self::assertTrue(Shadow::compile('cflow(execution(* *(..))) && within('.Caller::class.')')->isCallerSensitive());
    }

    #[Test]
    public function callEntriesAreMatchedWithTheirEnclosingStaticPart(): void
    {
        $call = StubJoinPoint::method(new Callee(), 'find', JoinPointKind::METHOD_CALL, [], new Caller(), StubJoinPoint::enclosingMethod(Caller::class, 'run'));
        $current = StubJoinPoint::method(new Callee(), 'find');
        $ctx = fn (string $expression): bool => Shadow::compile($expression)->matchesRuntime($this->context([$call, $current]));

        self::assertTrue($ctx('cflow(call(* '.Callee::class.'::find(..)))'));
        self::assertTrue($ctx('cflow(call(* '.Callee::class.'::find(..)) && within('.Caller::class.'))'));
        self::assertTrue($ctx('cflow(call(* '.Callee::class.'::find(..)) && withincode(* '.Caller::class.'::run(..)))'));
        self::assertFalse($ctx('cflow(call(* '.Callee::class.'::find(..)) && withincode(* '.Caller::class.'::tagged(..)))'));
        self::assertFalse($ctx('cflow(call(* '.Callee::class.'::find(..)) && within('.Callee::class.'))'));
        self::assertTrue($ctx('cflow(call(* '.Callee::class.'::find(..)) && this('.Caller::class.'))'));
        self::assertTrue($ctx('cflow(call(* '.Callee::class.'::find(..)) && target('.Callee::class.'))'));
    }

    #[Test]
    public function fieldAndHandlerEntriesAreRebuiltFromTheirSignatures(): void
    {
        $callee = new Callee();
        $write = StubJoinPoint::field($callee, 'count', JoinPointKind::FIELD_SET, 3);
        $caught = StubJoinPoint::handler(new DomainError('x'), new Caller());
        $current = StubJoinPoint::method(new Callee(), 'find');

        $ctx = $this->context([$write, $caught, $current]);

        self::assertTrue(Shadow::compile('cflow(set(int '.Callee::class.'::$count))')->matchesRuntime($ctx));
        self::assertFalse(Shadow::compile('cflow(get(* '.Callee::class.'::$count))')->matchesRuntime($ctx));
        self::assertTrue(Shadow::compile('cflow(handler('.DomainError::class.'))')->matchesRuntime($ctx));
        self::assertFalse(Shadow::compile('cflow(handler(LogicException))')->matchesRuntime($ctx));
    }

    #[Test]
    public function functionEntriesAreRebuiltFromTheirSignatures(): void
    {
        require_once __DIR__.'/../../Fixtures/Functions/functions.php';
        $function = StubJoinPoint::function('AOP\Tests\Fixtures\Functions\fmt', ['x']);
        $ctx = $this->context([$function, StubJoinPoint::method(new Callee(), 'find')]);

        self::assertTrue(Shadow::compile('cflow(execution(string AOP\Tests\Fixtures\Functions\fmt(string)))')->matchesRuntime($ctx));
        self::assertFalse(Shadow::compile('cflow(execution(int AOP\Tests\Fixtures\Functions\fmt(string)))')->matchesRuntime($ctx));
    }

    #[Test]
    public function theBindingsOfTheInnerPointcutPropagate(): void
    {
        $caller = new Caller();
        $pointcut = Shadow::compile('cflow('.self::RUN.' && this(c))', ['c' => Caller::class]);
        $ctx = $this->context([$this->runExecution($caller), StubJoinPoint::method(new Callee(), 'find')]);

        self::assertSame(['c'], array_keys($pointcut->bindings()));
        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertSame($caller, $ctx->bound()['c']);
    }

    #[Test]
    public function theInnermostMatchingEntryProvidesTheBoundValues(): void
    {
        $outer = StubJoinPoint::method(new Callee(), 'find', args: [1]);
        $middle = StubJoinPoint::method(new Callee(), 'find', args: [2]);
        $current = StubJoinPoint::method(new Callee(), 'find', args: [3]);

        $cflow = $this->context([$outer, $middle, $current]);
        $below = $this->context([$outer, $middle, $current]);

        self::assertTrue(Shadow::compile('cflow('.self::FIND.' && args(id))', ['id'])->matchesRuntime($cflow));
        self::assertSame(['id' => 3], $cflow->bound());
        self::assertTrue(Shadow::compile('cflowbelow('.self::FIND.' && args(id))', ['id'])->matchesRuntime($below));
        self::assertSame(['id' => 2], $below->bound());
    }

    #[Test]
    public function aFailingEntryBindsNothing(): void
    {
        $pointcut = Shadow::compile('cflow('.self::RUN.' && this(c))', ['c']);
        $ctx = $this->context([StubJoinPoint::method(new Callee(), 'find')]);

        self::assertFalse($pointcut->matchesRuntime($ctx));
        self::assertSame([], $ctx->bound());
    }

    #[Test]
    public function bindingsOfCflowAndOtherDesignatorsCombine(): void
    {
        $caller = new Caller();
        $pointcut = Shadow::compile('cflow('.self::RUN.' && this(c)) && args(id)', ['c' => Caller::class, 'id' => 'int']);
        $ctx = $this->context([$this->runExecution($caller), StubJoinPoint::method(new Callee(), 'find', args: [9])]);

        self::assertSame(['c', 'id'], array_keys($pointcut->bindings()));
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertSame(['c' => $caller, 'id' => 9], $ctx->bound());
    }

    #[Test]
    public function aNestedCflowSeesTheStackUpToItsEntry(): void
    {
        $make = StubJoinPoint::method(new Callee(), 'make');
        $run = $this->runExecution();
        $current = StubJoinPoint::method(new Callee(), 'find');
        $pointcut = Shadow::compile('cflow('.self::RUN.' && cflow(execution(* '.Callee::class.'::make(..))))');

        self::assertTrue($pointcut->matchesRuntime($this->context([$make, $run, $current])), 'make() was running when run() was entered');
        self::assertFalse($pointcut->matchesRuntime($this->context([$run, $make, $current])), 'make() came after run()');
    }

    #[Test]
    public function negationOfCflowWorksWithoutBindings(): void
    {
        $pointcut = Shadow::compile('!cflow('.self::RUN.')');

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime($this->context([StubJoinPoint::method(new Callee(), 'find')])));
        self::assertFalse($pointcut->matchesRuntime($this->context([$this->runExecution(), StubJoinPoint::method(new Callee(), 'find')])));
    }

    #[Test]
    public function negatedCflowCannotBind(): void
    {
        $this->expectException(BindingException::class);
        Shadow::compile('!cflow('.self::RUN.' && this(c))', ['c']);
    }

    #[Test]
    public function orBranchesMustBindTheSameNamesThroughCflow(): void
    {
        $or = Shadow::compile('cflow('.self::RUN.' && this(c)) || cflowbelow('.self::FIND.' && this(c))', ['c']);
        self::assertSame(['c'], array_keys($or->bindings()));

        $this->expectException(BindingException::class);
        Shadow::compile('cflow('.self::RUN.' && this(c)) || cflow('.self::FIND.')', ['c']);
    }

    #[Test]
    public function cflowCombinedWithStaticDesignators(): void
    {
        $pointcut = Shadow::compile(self::FIND.' && cflow('.self::RUN.')');
        $static = Shadow::method(Callee::class, 'find');

        self::assertTrue(Shadow::matches($pointcut, $static));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'make')));
        self::assertTrue($pointcut->matchesRuntime($this->context([$this->runExecution(), StubJoinPoint::method(new Callee(), 'find')])));
    }

    #[Test]
    public function aCflowInsideAnOrBranchDoesNotChangeTheOtherBranch(): void
    {
        $pointcut = Shadow::compile('(execution(* '.Callee::class.'::make(..)) && cflow('.self::RUN.')) || execution(* '.Callee::class.'::find(..))');

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime($this->context([StubJoinPoint::method(new Callee(), 'find')])), 'the second branch has no runtime part');
        self::assertFalse($pointcut->matchesRuntime($this->context([StubJoinPoint::method(new Callee(), 'make')], Callee::class, 'make')), 'make() outside run()');
        self::assertTrue($pointcut->matchesRuntime($this->context([$this->runExecution(), StubJoinPoint::method(new Callee(), 'make')], Callee::class, 'make')), 'make() inside run()');
    }

    // The runtime API: which join points to track

    /**
     * @param list<PointcutInterface> $pointcuts
     * @param class-string            $class
     */
    private function tracked(array $pointcuts, string $class, string $method, JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION): bool
    {
        $ctx = Shadow::method($class, $method, $kind);
        foreach ($pointcuts as $pointcut) {
            if (Shadow::matches($pointcut, $ctx)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function cflowPointcutsListsTheInnerPointcutsToTrack(): void
    {
        $pointcuts = ControlFlowPointcuts::of(Shadow::compile(self::FIND.' && cflow('.self::RUN.')'));

        self::assertCount(1, $pointcuts);
        self::assertTrue($this->tracked($pointcuts, Caller::class, 'run'));
        self::assertFalse($this->tracked($pointcuts, Callee::class, 'find'));
        self::assertFalse($this->tracked($pointcuts, Caller::class, 'run', JoinPointKind::METHOD_CALL));
    }

    #[Test]
    public function cflowPointcutsLooksThroughCombinators(): void
    {
        $pointcut = Shadow::compile('!cflow('.self::RUN.') || (execution(* *(..)) && cflowbelow(call(* '.Callee::class.'::make(..))))');
        $pointcuts = ControlFlowPointcuts::of($pointcut);

        self::assertCount(2, $pointcuts);
        self::assertTrue($this->tracked($pointcuts, Caller::class, 'run'));
        self::assertTrue($this->tracked($pointcuts, Callee::class, 'make', JoinPointKind::METHOD_CALL));
        self::assertFalse($this->tracked($pointcuts, Callee::class, 'find'));
    }

    #[Test]
    public function cflowPointcutsIncludesNestedOnes(): void
    {
        $pointcuts = ControlFlowPointcuts::of(Shadow::compile('cflow('.self::RUN.' && cflow('.self::FIND.'))'));

        self::assertCount(2, $pointcuts);
        self::assertTrue($this->tracked($pointcuts, Caller::class, 'run'));
        self::assertTrue($this->tracked($pointcuts, Callee::class, 'find'));
    }

    #[Test]
    public function cflowPointcutsIsEmptyWithoutCflow(): void
    {
        self::assertSame([], ControlFlowPointcuts::of(Shadow::compile(self::FIND.' && !within('.Caller::class.')')));
        self::assertSame([], ControlFlowPointcuts::of(Shadow::compile('if('.Conditions::class.'::always())')));
        self::assertSame([], ControlFlowPointcuts::of(new \AOP\Pointcut\TruePointcut()));
    }

    #[Test]
    public function theStaticHalfOfTheInnerPointcutDecidesWhatToTrack(): void
    {
        $pointcuts = ControlFlowPointcuts::of(Shadow::compile('cflow('.self::FIND.' && args(int))'));

        self::assertCount(1, $pointcuts);
        self::assertTrue($pointcuts[0]->isRuntime(), 'args() is runtime');
        self::assertTrue($this->tracked($pointcuts, Callee::class, 'find'), 'tracked on the static half; args() is checked when the cflow is consulted');
        self::assertFalse($this->tracked($pointcuts, Callee::class, 'make'));
    }
}
