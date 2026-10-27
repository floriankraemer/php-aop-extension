<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Advisor\IntroductionAdvisor;
use AOP\Aspect\AspectScope;
use AOP\Exception\AdviceReturnException;
use AOP\Exception\DeclareErrorException;
use AOP\JoinPoint\CatchClauseSignature;
use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\StaticMatchContext;
use AOP\Runtime\CflowEntry;
use AOP\Runtime\DeclareRule;
use AOP\Runtime\NativeRuntime;
use AOP\Runtime\RuntimeSettings;
use AOP\Tests\Fixtures\Api\MarkedTrait;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\AuditTrail;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\StubPointcut;
use AOP\Tests\Fixtures\Pointcut\SubCallee;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\Mode;
use AOP\Tests\Fixtures\Runtime\ProbePointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\TargetServiceChild;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives NativeRuntime::match() and dispatch() directly with raw descriptors: the decision bits, the eligibility rules,
 * the context handed to pointcuts, the plan cache key and the shape of the join points that reach the advice.
 */
final class NativeRuntimeTest extends TestCase
{
    private const ADVISE = 1;
    private const TRACK = 2;
    private const CALLER_SENSITIVE = 4;

    private static function runtime(RuntimeSettings $settings = new RuntimeSettings()): NativeRuntime
    {
        $runtime = new NativeRuntime();
        $runtime->configure($settings);

        return $runtime;
    }

    private static function advising(PointcutInterface $pointcut, Trace $trace = new Trace('t')): RuntimeSettings
    {
        return new RuntimeSettings(advisors: [new Advisor('a', null, 1, $pointcut, $trace)]);
    }

    /**
     * @param array<string, mixed>|null $caller
     *
     * @return array<string, mixed>
     */
    private static function descriptor(string $kind, ?string $class, string $name, ?string $scope = null, ?array $caller = null): array
    {
        return ['kind' => $kind, 'class' => $class, 'scope' => $scope ?? $class, 'name' => $name, 'static' => false, 'internal' => false, 'caller' => $caller];
    }

    /**
     * @param array<string, mixed>|null $caller
     *
     * @return array<string, mixed>
     */
    private static function method(string $class, string $name, ?array $caller = null): array
    {
        return self::descriptor('method-execution', $class, $name, null, $caller);
    }

    private static function always(): \Closure
    {
        return static fn (): bool => true;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function go(NativeRuntime $runtime, array $descriptor, ?object $self = null, mixed $result = 'original'): mixed
    {
        return $runtime->dispatch($descriptor, $self, [], static fn (array $a): mixed => $result);
    }

    // ------------------------------------------------------------ the decision

    #[Test]
    public function aPointcutMatchingTheClassButNotTheMethodAdvisesNothing(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(true, false)));

        self::assertSame(0, $runtime->match(self::method(Callee::class, 'find')));
    }

    #[Test]
    public function aPointcutMatchingTheMethodButNotTheClassAdvisesNothing(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(false, true)));

        self::assertSame(0, $runtime->match(self::method(Callee::class, 'find')));
    }

    #[Test]
    public function aDeclareRuleAdvisesTheJoinPointsItMatches(): void
    {
        $settings = new RuntimeSettings(declareRules: [new DeclareRule(Callee::class, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'find'), 'm', false)]);
        $runtime = self::runtime($settings);

        self::assertSame(self::ADVISE, $runtime->match(self::method(Callee::class, 'find')));
        self::assertSame(0, $runtime->match(self::method(Callee::class, 'total')));
    }

    #[Test]
    public function aFlowEntryTracksTheJoinPointsItMatchesWithoutAdvisingThem(): void
    {
        $settings = new RuntimeSettings(flowEntries: [new CflowEntry(Callee::class, AspectScope::PER_CFLOW, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'find'))]);
        $runtime = self::runtime($settings);

        self::assertSame(self::TRACK, $runtime->match(self::method(Callee::class, 'find')));
        self::assertSame(0, $runtime->match(self::method(Callee::class, 'total')));
    }

    #[Test]
    public function aFieldProbeAsksOnlyWhetherTheClassCanHaveSuchAJoinPoint(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(true, false)));

        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('field-get', Callee::class, '*')));
        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('field-set', Callee::class, '*')));
        self::assertSame(0, $runtime->match(self::descriptor('field-get', Callee::class, 'count')), 'a named field needs the method decision');
        self::assertSame(0, $runtime->match(self::descriptor('exception-handler', Callee::class, '*')), 'only field kinds are probed');
    }

    #[Test]
    public function callVariantsAreDerivedForConstructorsAndFunctionsToo(): void
    {
        $constructor = self::runtime(self::advising(new KindPointcut([JoinPointKind::CONSTRUCTOR_CALL])));
        $function = self::runtime(self::advising(new KindPointcut([JoinPointKind::FUNCTION_CALL])));
        $method = self::runtime(self::advising(new KindPointcut([JoinPointKind::METHOD_CALL])));

        self::assertSame(self::ADVISE, $constructor->match(self::descriptor('constructor-execution', Callee::class, '__construct')));
        self::assertSame(self::ADVISE, $function->match(self::descriptor('function-execution', null, 'str_repeat')));
        self::assertSame(self::ADVISE, $method->match(self::method(Callee::class, 'find')));
        self::assertSame(0, $constructor->match(self::method(Callee::class, 'find')));
    }

    #[Test]
    public function callerSensitivityComesFromAdvisorsDeclareRulesAndFlowEntries(): void
    {
        $sensitive = new StubPointcut(true, false, false, true);
        $declare = self::runtime(new RuntimeSettings(declareRules: [new DeclareRule(Callee::class, $sensitive, 'm', false)]));
        $flow = self::runtime(new RuntimeSettings(flowEntries: [new CflowEntry(Callee::class, AspectScope::PER_CFLOW, $sensitive)]));
        $advisor = self::runtime(self::advising($sensitive));

        self::assertSame(self::CALLER_SENSITIVE, $declare->match(self::method(Callee::class, 'find')));
        self::assertSame(self::CALLER_SENSITIVE, $flow->match(self::method(Callee::class, 'find')));
        self::assertSame(self::CALLER_SENSITIVE, $advisor->match(self::method(Callee::class, 'find')));
    }

    #[Test]
    public function callerSensitivityIsOnlyReportedWhileTheCallerIsUnknown(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(true, false, false, true)));
        $caller = ['class' => Callee::class, 'scope' => Callee::class, 'function' => 'find', 'file' => 'f.php'];

        self::assertSame(self::CALLER_SENSITIVE, $runtime->match(self::method(Callee::class, 'find')));
        self::assertSame(0, $runtime->match(self::method(Callee::class, 'find', $caller)));
    }

    #[Test]
    public function callerSensitivityAlsoAppliesToJoinPointsWithoutACallVariant(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(true, false, false, true)));

        self::assertSame(self::CALLER_SENSITIVE, $runtime->match(self::descriptor('field-get', Callee::class, 'count')));
    }

    #[Test]
    public function onlyCallerSensitivePointcutsMatchingTheClassMakeAJoinPointSensitive(): void
    {
        $settings = new RuntimeSettings(advisors: [
            new Advisor('plain', null, 1, new StubPointcut(true, false), new Trace('p')),
            new Advisor('sensitive', null, 2, new StubPointcut(false, false, false, true), new Trace('s')),
        ]);

        self::assertSame(0, self::runtime($settings)->match(self::method(Callee::class, 'find')));
    }

    #[Test]
    public function reconfiguringForgetsEarlierCallerSensitivity(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(true, false, false, true)));
        $runtime->configure(self::advising(new StubPointcut(true, false)));

        self::assertSame(0, $runtime->match(self::method(Callee::class, 'find')));
    }

    // ------------------------------------------------------------ eligibility

    #[Test]
    public function reservedNamesAreNeverAdvisedWhetherTheyAreTheClassTheScopeOrWrittenWithALeadingBackslash(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut()));

        self::assertSame(self::ADVISE, $runtime->match(self::method(TargetService::class, 'pub')));
        self::assertSame(0, $runtime->match(self::descriptor('method-execution', Weaver::class, 'enable', TargetService::class) + ['name' => 'pub']), 'reserved class');
        self::assertSame(0, $runtime->match(self::descriptor('method-execution', TargetService::class, 'enable', Weaver::class)), 'reserved scope');
        self::assertSame(0, $runtime->match(self::method('\\'.Weaver::class, 'enable')), 'leading backslash');
    }

    #[Test]
    public function aClassOrScopeThatIsAnEnumIsNeverAdvised(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut()));

        self::assertSame(0, $runtime->match(self::descriptor('method-execution', Mode::class, 'pub', TargetService::class)));
        self::assertSame(0, $runtime->match(self::descriptor('method-execution', TargetService::class, 'label', Mode::class)));
    }

    #[Test]
    public function aFieldOfNoClassIsMatchedWithoutReflectionOnTheClass(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut()));

        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('field-get', null, 'anything')));
    }

    #[Test]
    public function excludesAloneKeepMatchingTypesOut(): void
    {
        $runtime = self::runtime(new RuntimeSettings(advisors: [new Advisor('a', null, 1, new StubPointcut(), new Trace('t'))], excludes: [new TypePattern(Callee::class)]));

        self::assertSame(0, $runtime->match(self::method(Callee::class, 'find')));
        self::assertSame(self::ADVISE, $runtime->match(self::method(TargetService::class, 'pub')));
    }

    #[Test]
    public function includesLimitWeavingToMatchingTypesAndFunctions(): void
    {
        $runtime = self::runtime(new RuntimeSettings(advisors: [new Advisor('a', null, 1, new StubPointcut(), new Trace('t'))], includes: [new TypePattern(TargetService::class), new TypePattern('str_*')]));

        self::assertSame(self::ADVISE, $runtime->match(self::method(TargetService::class, 'pub')));
        self::assertSame(0, $runtime->match(self::method(Callee::class, 'find')));
        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('function-execution', null, 'str_repeat')));
        self::assertSame(0, $runtime->match(self::descriptor('function-execution', null, 'strlen')));
    }

    #[Test]
    public function excludedFunctionsAreNotAdvised(): void
    {
        $runtime = self::runtime(new RuntimeSettings(advisors: [new Advisor('a', null, 1, new StubPointcut(), new Trace('t'))], excludes: [new TypePattern('str_repeat')]));

        self::assertSame(0, $runtime->match(self::descriptor('function-execution', null, 'str_repeat')));
        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('function-execution', null, 'strrev')));
    }

    // ------------------------------------------------------------ the context

    #[Test]
    public function exceptionHandlersGetTheirCaughtTypeWhenItExists(): void
    {
        $probe = new ProbePointcut(self::always());
        $runtime = self::runtime(self::advising($probe));

        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('exception-handler', Callee::class, \RuntimeException::class)));
        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('exception-handler', Callee::class, \Throwable::class)));
        self::assertSame(self::ADVISE, $runtime->match(self::descriptor('exception-handler', Callee::class, 'No\\Such\\Exception')));

        self::assertSame(\RuntimeException::class, $probe->methodContexts[0]->caughtType?->getName());
        self::assertSame(\Throwable::class, $probe->methodContexts[1]->caughtType?->getName(), 'an interface');
        self::assertNull($probe->methodContexts[2]->caughtType, 'an unknown type is not an error');
    }

    #[Test]
    public function introducedInterfacesAreOfferedToThePointcutsOfClassesTheyApplyTo(): void
    {
        $probe = new ProbePointcut(self::always());
        $settings = new RuntimeSettings(
            advisors: [new Advisor('a', null, 1, $probe, new Trace('t'))],
            introductions: [new IntroductionAdvisor('Aspect', new TypePattern(TargetService::class), Auditable::class, AuditTrail::class, 1)],
        );
        $runtime = self::runtime($settings);

        $runtime->match(self::method(TargetService::class, 'pub'));
        $runtime->match(self::method(Callee::class, 'find'));
        $runtime->match(self::descriptor('function-execution', null, 'str_repeat'));

        self::assertSame([Auditable::class], $probe->methodContexts[0]->introducedInterfaces);
        self::assertSame([], $probe->methodContexts[1]->introducedInterfaces);
        self::assertSame([], $probe->methodContexts[2]->introducedInterfaces);
    }

    #[Test]
    public function theClosestClassServiceWins(): void
    {
        $probe = new ProbePointcut(self::always());
        $runtime = self::runtime(new RuntimeSettings(
            advisors: [new Advisor('a', null, 1, $probe, new Trace('t'))],
            classServices: [TargetServiceChild::class => 'child', TargetService::class => 'parent'],
        ));

        $runtime->match(self::method(TargetServiceChild::class, 'pub'));
        $runtime->match(self::method(TargetService::class, 'pub'));

        self::assertSame('child', $probe->methodContexts[0]->serviceName);
        self::assertSame('parent', $probe->methodContexts[1]->serviceName);
    }

    #[Test]
    public function aServiceNameIsOfferedOncePerKind(): void
    {
        $probe = new ProbePointcut(static fn (): bool => false);
        $runtime = self::runtime(new RuntimeSettings(advisors: [new Advisor('a', null, 1, $probe, new Trace('t'))], classServices: [TargetService::class => 'svc']));
        $runtime->registerInstanceService(new TargetService(), 'svc');
        $runtime->registerInstanceService(new TargetService(), 'other');

        $runtime->match(self::method(TargetService::class, 'pub'));

        // the execution and the call kind, each with the class service and the extra instance service, not 'svc' twice
        self::assertSame(['svc', 'other', 'svc', 'other'], array_map(static fn (StaticMatchContext $c): ?string => $c->serviceName, $probe->classContexts));
    }

    #[Test]
    public function aCallerIsOnlyOfferedToCallKindsAndDescribedByWhatIsKnownAboutIt(): void
    {
        $alone = new ProbePointcut(static fn (): bool => false);
        self::runtime(self::advising($alone))->match(self::method(Callee::class, 'find'));
        $probe = new ProbePointcut(static fn (): bool => false);
        $runtime = self::runtime(self::advising($probe));
        self::assertTrue(trait_exists(MarkedTrait::class));

        foreach ([Callee::class, \Countable::class, MarkedTrait::class, 'No\\Such\\Class'] as $class) {
            $runtime->match(self::method(Callee::class, 'find', ['class' => $class, 'scope' => null, 'function' => null, 'file' => 'caller.php']));
        }
        $calls = array_values(array_filter($probe->classContexts, static fn (StaticMatchContext $c): bool => JoinPointKind::METHOD_CALL === $c->kind));
        $executions = array_filter($probe->classContexts, static fn (StaticMatchContext $c): bool => JoinPointKind::METHOD_EXECUTION === $c->kind);

        self::assertNull($alone->classContexts[1]->caller, 'a call without a known caller');
        self::assertSame([Callee::class, \Countable::class, MarkedTrait::class, null], array_map(static fn (StaticMatchContext $c): ?string => $c->caller?->class?->getName(), $calls));
        self::assertSame('caller.php', $calls[0]->caller?->file);
        self::assertSame([null, null, null, null], array_map(static fn (StaticMatchContext $c): ?object => $c->caller, array_values($executions)), 'executions are not caller-relative');
    }

    #[Test]
    public function aCallersFunctionIsReflectedFromItsScopeOrAsAGlobalFunctionAndAClosureStaysUnknown(): void
    {
        $probe = new ProbePointcut(static fn (): bool => false);
        $runtime = self::runtime(self::advising($probe));

        foreach ([[Callee::class, 'find'], [null, 'str_repeat'], [Callee::class, '{closure}']] as [$scope, $function]) {
            $runtime->match(self::method(Callee::class, 'find', ['class' => $scope, 'scope' => $scope, 'function' => $function]));
        }
        $calls = array_values(array_filter($probe->classContexts, static fn (StaticMatchContext $c): bool => JoinPointKind::METHOD_CALL === $c->kind));

        self::assertSame('find', $calls[0]->caller?->function?->getName());
        self::assertSame('str_repeat', $calls[1]->caller?->function?->getName());
        self::assertNull($calls[2]->caller?->function);
        self::assertNotNull($calls[2]->caller);
    }

    // ------------------------------------------------------------ dispatching

    #[Test]
    public function aJoinPointThatIsNotEligibleRunsTheOriginalUntouched(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut()));
        $received = null;

        $result = $runtime->dispatch(self::method(Weaver::class, 'enable'), null, ['x'], static function (array $a) use (&$received): string {
            $received = $a;

            return 'original';
        });

        self::assertSame('original', $result);
        self::assertSame(['x'], $received);
    }

    #[Test]
    public function aFieldWriteIsGivenTheEnginesValueOverTheRawArguments(): void
    {
        $runtime = self::runtime(self::advising(new StubPointcut(), $trace = new Trace('set')));

        $runtime->dispatch(self::descriptor('field-set', Callee::class, 'count') + ['value' => 21], new Callee(), [5], static fn (array $a): null => null);

        self::assertSame([[21]], $trace->args);
    }

    #[Test]
    public function callAndExecutionJoinPointsDescribeWhoCallsWhatOnWhom(): void
    {
        $caller = new Caller();
        $callee = new Callee();
        $callerArray = ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run', 'file' => 'caller.php', 'line' => 12, 'object' => $caller];
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));

        $runtime->dispatch(self::method(Callee::class, 'find', $callerArray), $callee, [5], static fn (array $a): string => 'found');
        [$call, $execution] = $trace->seen;

        self::assertSame(JoinPointKind::METHOD_CALL, $call->getKind());
        self::assertSame($caller, $call->getThis());
        self::assertSame($callee, $call->getTarget());
        $callLocation = $call->getSourceLocation();
        self::assertNotNull($callLocation);
        self::assertSame(12, $callLocation->line);
        self::assertSame('caller.php', $callLocation->file);
        self::assertSame(Caller::class, $callLocation->class);
        self::assertSame('run', $callLocation->function);
        self::assertSame('method-execution(Caller::run(..))', $call->getEnclosingStaticPart()?->toShortString());
        self::assertSame(JoinPointKind::METHOD_EXECUTION, $execution->getKind());
        self::assertSame($callee, $execution->getThis());
        self::assertSame($callee, $execution->getTarget());
        self::assertTrue($execution->isOutermost(), 'an engine descriptor without the key is outermost');
        $executionLocation = $execution->getSourceLocation();
        self::assertNotNull($executionLocation);
        self::assertSame(Callee::class, $executionLocation->class);
        self::assertSame('find', $executionLocation->function);
        self::assertGreaterThan(0, $executionLocation->line);
    }

    #[Test]
    public function staticMembersHaveNoTargetAndNestedExecutionsAreNotOutermost(): void
    {
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));

        $runtime->dispatch(['static' => true, 'outermost' => false] + self::method(Callee::class, 'total'), new Callee(), [], static fn (array $a): int => 0);

        self::assertNull($trace->seen[0]->getTarget());
        self::assertFalse($trace->seen[0]->isOutermost());
    }

    #[Test]
    public function aHandlerRunsInTheObjectThatCatchesWithoutATarget(): void
    {
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));
        $object = new Callee();

        $runtime->dispatch(self::descriptor('exception-handler', Callee::class, \RuntimeException::class) + ['exception' => new \RuntimeException()], $object, [], static fn (array $a): null => null);

        self::assertSame($object, $trace->seen[0]->getThis());
        self::assertNull($trace->seen[0]->getTarget());
        self::assertInstanceOf(CatchClauseSignature::class, $trace->seen[0]->getSignature());
    }

    #[Test]
    public function aNestedConstructorExecutionHasNoCallJoinPointAndAnOutermostOneHas(): void
    {
        $caller = ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run', 'object' => new Caller()];
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));
        $descriptor = self::descriptor('constructor-execution', Callee::class, '__construct', null, $caller);

        $runtime->dispatch($descriptor + ['outermost' => true], new Callee(), [], static fn (array $a): null => null);
        $outermost = array_map(static fn (MethodInvocation $jp): JoinPointKind => $jp->getKind(), $trace->seen);
        $runtime->dispatch($descriptor + ['outermost' => false], new Callee(), [], static fn (array $a): null => null);
        $nested = array_map(static fn (MethodInvocation $jp): JoinPointKind => $jp->getKind(), array_slice($trace->seen, count($outermost)));

        self::assertSame([JoinPointKind::CONSTRUCTOR_CALL, JoinPointKind::CONSTRUCTOR_EXECUTION], $outermost);
        self::assertSame([JoinPointKind::CONSTRUCTOR_EXECUTION], $nested);
    }

    #[Test]
    public function aCallerThatIsAConstructorIsDescribedAsAConstructorExecution(): void
    {
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));

        $runtime->dispatch(self::method(Callee::class, 'find', ['class' => Callee::class, 'scope' => Callee::class, 'function' => '__construct']), new Callee(), [1], static fn (array $a): string => 'x');
        $runtime->dispatch(self::method(Callee::class, 'find', ['class' => null, 'scope' => null, 'function' => 'str_repeat']), new Callee(), [1], static fn (array $a): string => 'x');

        self::assertSame(JoinPointKind::CONSTRUCTOR_EXECUTION, $trace->seen[0]->getEnclosingStaticPart()?->kind);
        self::assertInstanceOf(ConstructorSignature::class, $trace->seen[0]->getEnclosingStaticPart()->signature);
        self::assertSame(JoinPointKind::FUNCTION_EXECUTION, $trace->seen[2]->getEnclosingStaticPart()?->kind);
    }

    #[Test]
    public function signaturesFollowTheKindOfJoinPoint(): void
    {
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));
        $noop = static fn (array $a): null => null;

        $runtime->dispatch(self::descriptor('constructor-execution', Callee::class, '__construct'), new Callee(), [], $noop);
        $runtime->dispatch(self::descriptor('function-execution', null, 'str_repeat'), null, ['a', 1], static fn (array $a): string => 'a');
        $runtime->dispatch(self::descriptor('field-get', Callee::class, 'count'), new Callee(), [], $noop);
        $runtime->dispatch(self::descriptor('initialization', SubCallee::class, '__construct', Callee::class), new SubCallee(), [], $noop);
        [$constructor, $function, $field, $initialization] = array_map(static fn ($jp) => $jp->getSignature(), $trace->seen);
        self::assertInstanceOf(ConstructorSignature::class, $constructor);

        self::assertSame(Callee::class.'::__construct(int)', $constructor->toLongString(), 'carries the constructor reflection');
        self::assertInstanceOf(FunctionSignature::class, $function);
        self::assertSame('str_repeat', $function->getName());
        self::assertInstanceOf(FieldSignature::class, $field);
        self::assertSame('count', $field->getName());
        self::assertInstanceOf(ConstructorSignature::class, $initialization);
        self::assertSame(SubCallee::class, $initialization->getDeclaringTypeName(), 'the instantiated class, not the scope');
        self::assertNull($initialization->constructor);
    }

    #[Test]
    public function fieldAccessesAreRelativeToTheirCallerButExecutionsAndInitializationsAreNot(): void
    {
        $probe = new ProbePointcut(self::always());
        $runtime = self::runtime(self::advising($probe));
        $caller = ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run'];
        $noop = static fn (array $a): null => null;

        $runtime->dispatch(self::descriptor('field-get', Callee::class, 'count', null, $caller), new Callee(), [], $noop);
        $runtime->dispatch(self::descriptor('field-get', Callee::class, 'label'), new Callee(), [], $noop);
        $runtime->dispatch(self::descriptor('initialization', Callee::class, '__construct', null, $caller), new Callee(), [], $noop);
        $runtime->dispatch(self::method(Callee::class, 'make', $caller), null, [], static fn (array $a): Callee => new Callee());
        $byKind = static function (JoinPointKind $kind) use ($probe): array {
            return array_values(array_filter($probe->methodContexts, static fn (StaticMatchContext $c): bool => $kind === $c->kind));
        };

        self::assertSame('run', $byKind(JoinPointKind::FIELD_GET)[0]->caller?->function?->getName());
        self::assertNull($byKind(JoinPointKind::FIELD_GET)[1]->caller);
        self::assertNull($byKind(JoinPointKind::INITIALIZATION)[0]->caller);
        self::assertNull($byKind(JoinPointKind::METHOD_EXECUTION)[0]->caller);
    }

    #[Test]
    public function aDeclareErrorOnACallKindThrowsOnlyWhereThereIsACaller(): void
    {
        $rule = new DeclareRule(Callee::class, new KindPointcut([JoinPointKind::METHOD_CALL]), 'no calls', true);
        $runtime = self::runtime(new RuntimeSettings(declareRules: [$rule]));
        $caller = ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run'];

        self::assertSame('original', self::go($runtime, self::method(Callee::class, 'find'), new Callee()));
        $this->expectException(DeclareErrorException::class);
        $this->expectExceptionMessage('no calls [method-call(Callee::find(..)), declared by '.Callee::class.']');
        self::go($runtime, self::method(Callee::class, 'find', $caller), new Callee());
    }

    #[Test]
    public function aDeclareWarningIsRaisedOncePerRuleAndJoinPointAndNotAgainAfterReconfiguring(): void
    {
        $rules = [
            new DeclareRule(Callee::class, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), 'first', false),
            new DeclareRule(Callee::class, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), 'second', false),
        ];
        $settings = new RuntimeSettings(declareRules: $rules);
        $runtime = self::runtime($settings);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            self::go($runtime, self::method(Callee::class, 'find'), new Callee());
            self::go($runtime, self::method(Callee::class, 'make'));
            $runtime->configure($settings);
            self::go($runtime, self::method(Callee::class, 'find'), new Callee());
        } finally {
            restore_error_handler();
        }

        self::assertSame([
            'first [method-execution(Callee::find(..)), declared by '.Callee::class.']',
            'second [method-execution(Callee::find(..)), declared by '.Callee::class.']',
            'first [method-execution(Callee::make(..)), declared by '.Callee::class.']',
            'second [method-execution(Callee::make(..)), declared by '.Callee::class.']',
        ], $warnings);
    }

    // ------------------------------------------------------------ plans and flow

    /**
     * @param array<string, mixed> $caller
     */
    private static function callAdvisedBy(NativeRuntime $runtime, Trace $trace, array $caller): int
    {
        $before = count($trace->seen);
        $runtime->dispatch(self::method(Callee::class, 'find', $caller), new Callee(), [1], static fn (array $a): string => 'x');

        return count($trace->seen) - $before;
    }

    #[Test]
    public function callerSpecificPlansAreKeptApartByCallerClassScopeAndFunction(): void
    {
        $trace = new Trace('t');
        $wants = static function (callable $of): ProbePointcut {
            return new ProbePointcut(static fn (StaticMatchContext $c): bool => JoinPointKind::METHOD_CALL === $c->kind && $of($c));
        };
        $find = ['class' => Callee::class, 'scope' => Callee::class, 'function' => 'find'];

        $byClass = self::runtime(self::advising($wants(static fn (StaticMatchContext $c): bool => Callee::class === $c->caller?->class?->getName()), $trace));
        self::assertSame(1, self::callAdvisedBy($byClass, $trace, $find));
        self::assertSame(0, self::callAdvisedBy($byClass, $trace, ['class' => SubCallee::class] + $find), 'another caller class');

        $byFunction = self::runtime(self::advising($wants(static fn (StaticMatchContext $c): bool => 'find' === $c->caller?->function?->getName()), $trace));
        self::assertSame(1, self::callAdvisedBy($byFunction, $trace, $find));
        self::assertSame(0, self::callAdvisedBy($byFunction, $trace, ['function' => 'make'] + $find), 'another caller function');

        $byScope = self::runtime(self::advising($wants(static fn (StaticMatchContext $c): bool => ($f = $c->caller?->function) instanceof \ReflectionMethod && Callee::class === $f->class), $trace));
        $constructor = ['class' => Callee::class, 'scope' => Callee::class, 'function' => '__construct'];
        self::assertSame(1, self::callAdvisedBy($byScope, $trace, $constructor));
        self::assertSame(0, self::callAdvisedBy($byScope, $trace, ['scope' => SubCallee::class] + $constructor), 'another caller scope');
    }

    #[Test]
    public function outermostAndNestedExecutionsDoNotShareAPlan(): void
    {
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));
        $caller = ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run', 'object' => new Caller()];
        $descriptor = self::descriptor('constructor-execution', Callee::class, '__construct', null, $caller);
        $noop = static fn (array $a): null => null;

        $runtime->dispatch($descriptor + ['outermost' => false], new Callee(), [], $noop);
        self::assertCount(1, $trace->seen);
        $runtime->dispatch($descriptor + ['outermost' => true], new Callee(), [], $noop);
        self::assertCount(3, $trace->seen);
    }

    #[Test]
    public function aFlowEntryPutsItsJoinPointOnTheControlFlowStack(): void
    {
        $entry = new CflowEntry(Callee::class, AspectScope::PER_CFLOW, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'find'));
        $runtime = self::runtime(new RuntimeSettings(flowEntries: [$entry]));
        $depth = static fn (array $a): int => count($runtime->controlFlow()->stack());

        self::assertSame(1, $runtime->dispatch(self::method(Callee::class, 'find'), new Callee(), [], $depth));
        self::assertSame(0, $runtime->dispatch(self::method(Callee::class, 'total'), null, [], $depth));
    }

    #[Test]
    public function aJoinPointAnAdvisorsCflowNeedsIsTrackedEvenWhenNoAdviceRunsThere(): void
    {
        $advisor = new ProbePointcut(static fn (): bool => false, null, false, [new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'find')]);
        $runtime = self::runtime(self::advising($advisor));
        $depth = static fn (array $a): int => count($runtime->controlFlow()->stack());

        self::assertSame(self::TRACK, $runtime->match(self::method(Callee::class, 'find')));
        self::assertSame(1, $runtime->dispatch(self::method(Callee::class, 'find'), new Callee(), [], $depth));
        self::assertSame(0, $runtime->dispatch(self::method(Callee::class, 'total'), null, [], $depth));
    }

    // ------------------------------------------------------------ around advice results

    #[Test]
    public function theReturnTypeErrorNamesOnlyTheAroundAdvisorsOfTheJoinPoint(): void
    {
        $settings = new RuntimeSettings(advisors: [
            new Advisor('around-one', null, 1, new StubPointcut(), new Trace('a', static fn (): int => 5)),
            new Advisor('before-one', null, 2, new StubPointcut(), new Trace('b'), AdviceType::BEFORE),
        ]);
        $runtime = self::runtime($settings);

        try {
            $runtime->dispatch(self::method(Callee::class, 'find'), new Callee(), [1], static fn (array $a): string => 'x');
            self::fail('expected');
        } catch (AdviceReturnException $e) {
            self::assertSame('Around advice around-one returned int, but Callee::find(..) is declared to return string.', $e->getMessage());
        }
    }

    #[Test]
    public function aDescriptorWithoutStaticOrOutermostKeysIsAnInstanceMemberAndOutermost(): void
    {
        $caller = ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run', 'object' => new Caller()];
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));
        $callee = new Callee();
        $descriptor = ['kind' => 'constructor-execution', 'class' => Callee::class, 'scope' => Callee::class, 'name' => '__construct', 'caller' => $caller];

        $runtime->dispatch($descriptor, $callee, [], static fn (array $a): null => null);

        self::assertCount(2, $trace->seen, 'call and execution');
        self::assertSame($callee, $trace->seen[1]->getTarget());
        self::assertTrue($trace->seen[1]->isOutermost());
    }

    #[Test]
    public function aFieldAccessRunsInTheCallersObject(): void
    {
        $caller = new Caller();
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));
        $callee = new Callee();

        $runtime->dispatch(self::descriptor('field-get', Callee::class, 'count', null, ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run', 'object' => $caller]), $callee, [], static fn (array $a): int => 0);

        self::assertSame($caller, $trace->seen[0]->getThis());
        self::assertSame($callee, $trace->seen[0]->getTarget());
    }

    #[Test]
    public function aRuleThatDoesNotApplyDoesNotStopTheLaterOnes(): void
    {
        $rules = [
            new DeclareRule(Callee::class, new KindPointcut([JoinPointKind::FUNCTION_EXECUTION]), 'not here', true),
            new DeclareRule(Callee::class, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), 'here', true),
        ];
        $runtime = self::runtime(new RuntimeSettings(declareRules: $rules));

        $this->expectException(DeclareErrorException::class);
        $this->expectExceptionMessage('here [');
        self::go($runtime, self::method(Callee::class, 'find'), new Callee());
    }

    #[Test]
    public function methodJoinPointsCarryAMethodSignature(): void
    {
        $runtime = self::runtime(self::advising(new ProbePointcut(self::always()), $trace = new Trace('t')));

        $runtime->dispatch(self::method(Callee::class, 'find', ['class' => Caller::class, 'scope' => Caller::class, 'function' => 'run']), new Callee(), [1], static fn (array $a): string => 'x');

        self::assertInstanceOf(MethodSignature::class, $trace->seen[1]->getSignature());
        self::assertSame('Callee::find(..)', $trace->seen[0]->getSignature()->toShortString());
    }

    #[Test]
    public function planRequestsOfAnAdvisedInstanceAndOfOtherInstancesOfItsClassStayApart(): void
    {
        $runtime = self::runtime();
        $advised = new Callee();
        $other = new Callee();
        $runtime->advised($advised, static fn (): null => null, static fn (): bool => false)->addAdvisor(new Advisor('mine', null, 1, new ProbePointcut(self::always()), $mine = new Trace('mine')));
        $runtime->advised($advised, static fn (): null => null, static fn (): bool => false);
        $second = new Callee();
        $runtime->advised($second, static fn (): null => null, static fn (): bool => false)->addAdvisor(new Advisor('second', null, 1, new ProbePointcut(self::always()), $secondTrace = new Trace('second')));
        $descriptor = self::method(Callee::class, 'find');
        $call = static fn (object $on) => $runtime->dispatch($descriptor, $on, [1], static fn (array $a): string => 'x');

        $call($other);
        $call($advised);
        $call($other);
        $call($advised);
        $call($second);
        $call($advised);

        self::assertCount(3, $mine->seen);
        self::assertCount(1, $secondTrace->seen);
    }
}
