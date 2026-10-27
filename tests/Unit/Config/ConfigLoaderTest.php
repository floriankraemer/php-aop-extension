<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Config;

use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Advisor\Api\AdvisorAdapterRegistry;
use AOP\Aspect\ArrayAspectInstanceResolver;
use AOP\Aspect\AspectScope;
use AOP\Config\ArrayNamedPointcutResolver;
use AOP\Config\ConfigLoader;
use AOP\Config\ConfigResult;
use AOP\Config\ConfigurationException;
use AOP\Exception\PointcutSyntaxException;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\AuditAdvice;
use AOP\Tests\Fixtures\Api\BeforeOnly;
use AOP\Tests\Fixtures\Api\Describable;
use AOP\Tests\Fixtures\Api\DescribableImpl;
use AOP\Tests\Fixtures\Api\NeedsArgsAspect;
use AOP\Tests\Fixtures\Api\PlainAspect;
use AOP\Tests\Fixtures\Api\Run;
use AOP\Tests\Fixtures\Api\SecondAspect;
use AOP\Tests\Fixtures\Aspect\RecordingAspectA;
use AOP\Tests\Fixtures\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private const ALL = 'execution(* AOP\Tests\Fixtures\Api\Account::*(..))';

    protected function setUp(): void
    {
        Log::reset();
    }

    private function plain(ConfigResult $result): PlainAspect
    {
        $aspect = $result->aspectInstances()[PlainAspect::class];
        self::assertInstanceOf(PlainAspect::class, $aspect);

        return $aspect;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function execute(ConfigResult $result, string $method, array $args = []): mixed
    {
        return Run::call($result->advisors(), new Account(), $method, $args, new ArrayAspectInstanceResolver($result->aspectInstances()));
    }

    /**
     * @param array<string, mixed> $advice
     *
     * @return array<string, mixed>
     */
    private static function config(array ...$advice): array
    {
        return ['aspects' => [['class' => PlainAspect::class, 'advice' => $advice]]];
    }

    #[Test]
    public function turnsPlainAspectsIntoAdvisorsThatBehaveLikeAttributeAspects(): void
    {
        $result = ConfigLoader::load([
            'pointcuts' => ['account' => self::ALL],
            'aspects' => [[
                'id' => 'audit',
                'class' => PlainAspect::class,
                'order' => 10,
                'advice' => [
                    ['type' => 'after', 'pointcut-ref' => 'account', 'method' => 'after'],
                    ['type' => 'before', 'pointcut-ref' => 'account', 'method' => 'before'],
                    ['type' => 'around', 'pointcut' => self::ALL, 'method' => 'around'],
                ],
            ]],
        ]);

        self::assertSame(105, $this->execute($result, 'deposit', [5]));
        self::assertSame(['around-in', 'before:deposit', 'after:deposit', 'around-out'], $this->plain($result)->log, 'around, before, after: the order of attribute aspects');
    }

    #[Test]
    public function producesTheSameMetadataAndAdvisorsAsAttributeAspects(): void
    {
        $result = ConfigLoader::load(self::config(
            ['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before'],
            ['type' => 'after-returning', 'pointcut' => self::ALL, 'method' => 'returned', 'returning' => 'result'],
        ));

        [$meta] = $result->metadata();
        self::assertSame(PlainAspect::class, $meta->aspectClass);
        self::assertSame(PHP_INT_MAX, $meta->order);
        self::assertSame(AspectScope::SINGLETON, $meta->scope);
        self::assertSame([0, 1], array_map(static fn ($a): int => $a->declarationIndex, $meta->advices));
        self::assertSame([AdviceType::BEFORE, AdviceType::AFTER_RETURNING], array_map(static fn ($a): AdviceType => $a->type, $meta->advices));
        self::assertSame('result', $meta->advices[1]->returning);

        $advisors = $result->aspectAdvisors();
        self::assertSame([PlainAspect::class.'::before', PlainAspect::class.'::returned'], array_map(static fn (Advisor $a): string => $a->id, $advisors));
        self::assertSame($meta->advices[0], $advisors[0]->advice);
        self::assertNull($advisors[0]->interceptor);
        self::assertSame($advisors, $result->advisors());
        self::assertSame([], $result->programmaticAdvisors());
    }

    #[Test]
    public function bindsReturningThrowingAndPointcutArguments(): void
    {
        $result = ConfigLoader::load(self::config(
            ['type' => 'after-returning', 'pointcut' => self::ALL, 'method' => 'returned', 'returning' => '$result'],
            ['type' => 'after-throwing', 'pointcut' => self::ALL, 'method' => 'failed', 'throwing' => 'error'],
            ['type' => 'around', 'pointcut' => 'execution(* AOP\Tests\Fixtures\Api\Account::deposit(..)) && args(amount, ..)', 'method' => 'withArgument', 'arg-names' => ['pjp', '$amount']],
        ));

        $this->execute($result, 'deposit', [5]);
        try {
            $this->execute($result, 'fail');
            self::fail();
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(['amount:5', 'returned:105', 'failed:bad'], $this->plain($result)->log);
    }

    #[Test]
    public function aspectLocalPointcutsOverrideSharedOnesAndMayReferenceEachOther(): void
    {
        $result = ConfigLoader::load([
            'pointcuts' => [
                'balance' => 'execution(* AOP\Tests\Fixtures\Api\Account::getBalance(..))',
                'either' => 'balance() || execution(* AOP\Tests\Fixtures\Api\Account::deposit(..))',
            ],
            'aspects' => [[
                'class' => PlainAspect::class,
                'pointcuts' => ['balance' => 'execution(* AOP\Tests\Fixtures\Api\Account::setBalance(..))'],
                'advice' => [['type' => 'before', 'pointcut-ref' => 'either', 'method' => 'before']],
            ]],
        ]);

        $this->execute($result, 'getBalance');
        $this->execute($result, 'setBalance', [1]);
        $this->execute($result, 'deposit', [1]);

        self::assertSame(['before:setBalance', 'before:deposit'], $this->plain($result)->log, 'the local "balance" replaced the shared one inside this aspect');
        self::assertArrayHasKey('either', $result->metadata()[0]->namedPointcuts);
    }

    #[Test]
    public function pointcutRefsMayNameAPointcutOfAnotherConfiguredAspect(): void
    {
        $result = ConfigLoader::load(['aspects' => [
            ['class' => SecondAspect::class, 'pointcuts' => ['balance' => 'execution(* AOP\Tests\Fixtures\Api\Account::getBalance(..))']],
            ['class' => PlainAspect::class, 'advice' => [['type' => 'before', 'pointcut-ref' => SecondAspect::class.'::balance', 'method' => 'before']]],
        ]]);

        $this->execute($result, 'getBalance');
        $this->execute($result, 'deposit', [1]);

        self::assertSame(['before:getBalance'], $this->plain($result)->log);
    }

    #[Test]
    public function orderAndScopeFlowIntoTheMetadata(): void
    {
        $result = ConfigLoader::load(['aspects' => [
            ['class' => PlainAspect::class, 'order' => 20, 'scope' => 'pertarget', 'advice' => [['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before']]],
            ['class' => SecondAspect::class, 'order' => 10, 'advice' => [['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before']]],
        ]]);

        self::assertSame([20, 10], array_map(static fn ($m): int => $m->order, $result->metadata()));
        self::assertSame(AspectScope::PER_TARGET, $result->metadata()[0]->scope);
        self::assertSame(AspectScope::PER_TARGET, $result->metadata()[0]->advices[0]->scope);
        self::assertSame([20, 10], array_map(static fn (Advisor $a): int => $a->order, $result->advisors()));

        $instances = $result->aspectInstances();
        self::assertInstanceOf(\Closure::class, $instances[PlainAspect::class], 'pertarget aspects get a factory');
        self::assertInstanceOf(SecondAspect::class, $instances[SecondAspect::class], 'singletons an instance');
    }

    #[Test]
    public function perTargetAspectsGetOneInstancePerTarget(): void
    {
        $result = ConfigLoader::load(['aspects' => [
            ['class' => PlainAspect::class, 'scope' => 'pertarget', 'advice' => [['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before']]],
        ]]);
        $resolver = new ArrayAspectInstanceResolver($result->aspectInstances());
        $a = new Account();
        $b = new Account();

        Run::call($result->advisors(), $a, 'getBalance', [], $resolver);
        Run::call($result->advisors(), $a, 'getBalance', [], $resolver);
        Run::call($result->advisors(), $b, 'getBalance', [], $resolver);

        $forA = $resolver->get(PlainAspect::class, $a);
        $forB = $resolver->get(PlainAspect::class, $b);
        self::assertInstanceOf(PlainAspect::class, $forA);
        self::assertInstanceOf(PlainAspect::class, $forB);
        self::assertCount(2, $forA->log);
        self::assertCount(1, $forB->log);
    }

    #[Test]
    public function aspectsComeFromAnInstanceAFactoryOrAReference(): void
    {
        $instance = new SecondAspect('given');
        $created = [];
        $factory = function (string $classOrRef) use (&$created): object {
            $created[] = $classOrRef;

            return 'bean' === $classOrRef ? new SecondAspect('bean') : new PlainAspect();
        };
        $before = static fn (string $class): array => ['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before'];

        $viaInstance = ConfigLoader::load(['aspects' => [['instance' => $instance, 'advice' => [$before('')]]]]);
        $viaFactory = ConfigLoader::load(['aspects' => [['class' => PlainAspect::class, 'advice' => [$before('')]]]], $factory);
        $viaRef = ConfigLoader::load(['aspects' => [['ref' => 'bean', 'advice' => [$before('')]]]], $factory);

        self::assertSame($instance, $viaInstance->aspectInstances()[SecondAspect::class]);
        self::assertSame([PlainAspect::class, 'bean'], $created);
        self::assertInstanceOf(PlainAspect::class, $viaFactory->aspectInstances()[PlainAspect::class]);
        $bean = $viaRef->aspectInstances()[SecondAspect::class];
        self::assertInstanceOf(SecondAspect::class, $bean);
        self::assertSame('bean', $bean->name);

        $this->execute($viaRef, 'getBalance');
        self::assertSame(['bean:before'], Log::$entries);
    }

    #[Test]
    public function perThisRefsAreRecreatedByTheFactory(): void
    {
        $calls = 0;
        $result = ConfigLoader::load(['aspects' => [['ref' => 'x', 'scope' => 'perthis', 'advice' => [['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before']]]]], function () use (&$calls): object {
            ++$calls;

            return new SecondAspect();
        });
        self::assertSame(1, $calls, 'created once to learn the class');

        $factory = $result->aspectInstances()[SecondAspect::class];
        self::assertInstanceOf(\Closure::class, $factory);
        self::assertNotSame($factory(), $factory());
    }

    #[Test]
    public function declareParentsBecomeIntroductions(): void
    {
        $result = ConfigLoader::load(['aspects' => [[
            'class' => PlainAspect::class,
            'declare-parents' => [['types-matching' => 'AOP\Tests\Fixtures\Api\Account+', 'implement-interface' => '\\'.Describable::class, 'default-impl' => DescribableImpl::class]],
        ]]]);

        [$introduction] = $result->metadata()[0]->introductions;
        self::assertSame('AOP\Tests\Fixtures\Api\Account+', $introduction->typePattern);
        self::assertSame(Describable::class, $introduction->interface);
        self::assertSame(DescribableImpl::class, $introduction->defaultImpl);
    }

    #[Test]
    public function advisorsEntriesProduceProgrammaticAdvisors(): void
    {
        $result = ConfigLoader::load([
            'pointcuts' => ['balance' => 'execution(* AOP\Tests\Fixtures\Api\Account::getBalance(..))'],
            'advisors' => [
                ['id' => 'first', 'advice' => new BeforeOnly('one'), 'pointcut-ref' => 'balance', 'order' => 2],
                ['advice-ref' => 'two', 'pointcut' => self::ALL, 'order' => 1],
            ],
        ], static fn (string $ref): object => new BeforeOnly($ref));

        $advisors = $result->programmaticAdvisors();
        self::assertSame(['first', BeforeOnly::class.'#'], [$advisors[0]->id, substr($advisors[1]->id, 0, strlen(BeforeOnly::class) + 1)]);
        self::assertSame([2, 1], [$advisors[0]->order, $advisors[1]->order]);
        self::assertNull($advisors[0]->advice);

        Run::call($result->advisors(), new Account(), 'getBalance');
        self::assertSame(['two', 'one'], Log::$entries);
    }

    #[Test]
    public function advisorsUseTheGivenAdapterRegistry(): void
    {
        $adapters = new AdvisorAdapterRegistry();
        $adapters->registerAdvisorAdapter(new \AOP\Advisor\Api\ClosureAdvisorAdapter(
            static fn (object $a): bool => $a instanceof AuditAdvice,
            static fn (object $a): \AOP\Interceptor\MethodInterceptorInterface => new \AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor(),
        ));

        $result = ConfigLoader::load(['advisors' => [['advice' => new AuditAdvice(), 'pointcut' => self::ALL]]], null, $adapters);

        self::assertCount(1, $result->programmaticAdvisors());
    }

    #[Test]
    public function anEmptyConfigurationIsFine(): void
    {
        $result = ConfigLoader::load([]);

        self::assertSame([], $result->advisors());
        self::assertSame([], $result->metadata());
        self::assertSame([], $result->aspectInstances());
    }

    #[Test]
    public function theResolverKnowsTheConfiguredAspects(): void
    {
        $result = ConfigLoader::load(['aspects' => [['class' => PlainAspect::class, 'pointcuts' => ['all' => self::ALL]]]]);

        self::assertSame(self::ALL, $result->namedPointcutResolver()->resolve(null, 'all', PlainAspect::class));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        $advice = static fn (array $override = []): array => ['aspects' => [['class' => PlainAspect::class, 'advice' => [[...['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before'], ...$override]]]]];
        $aspect = static fn (array $override): array => ['aspects' => [[...['class' => PlainAspect::class], ...$override]]];

        yield 'unknown top-level key' => [['aspect' => []], 'aop: unknown key(s) "aspect"; allowed: pointcuts, aspects, advisors'];
        yield 'aspects not a list' => [['aspects' => ['a' => []]], 'aop.aspects: expected a list, a map given'];
        yield 'aspect not an array' => [['aspects' => ['x']], 'aop.aspects[0]: expected an array, string given'];
        yield 'no class' => [['aspects' => [[]]], 'aop.aspects[0]: give exactly one of "class", "ref" and "instance" (found none)'];
        yield 'two sources' => [['aspects' => [['class' => PlainAspect::class, 'ref' => 'x']]], 'found class, ref'];
        yield 'unknown class' => [['aspects' => [['class' => 'No\Such']]], 'aspect class "No\Such" does not exist'];
        yield 'instance not an object' => [['aspects' => [['instance' => 'x']]], '"instance" must be an object'];
        yield 'ref without factory' => [['aspects' => [['ref' => 'x']]], '"ref" => "x" needs an $aspectFactory'];
        yield 'needs constructor arguments' => [['aspects' => [['class' => NeedsArgsAspect::class]]], 'needs constructor arguments; pass an $aspectFactory'];
        yield 'attribute aspect' => [['aspects' => [['class' => RecordingAspectA::class]]], 'is an attribute aspect (#[Aspect])'];
        yield 'bad scope' => [$aspect(['scope' => 'request']), '"scope" must be one of singleton, pertarget, perthis'];
        yield 'bad order' => [$aspect(['order' => '1']), '"order" must be an integer, string given'];
        yield 'unknown aspect key' => [$aspect(['pointcut' => 'x']), 'aop.aspects[0]: unknown key(s) "pointcut"'];
        yield 'duplicate aspect' => [['aspects' => [['class' => PlainAspect::class], ['class' => PlainAspect::class]]], 'aspect class '.PlainAspect::class.' is configured twice'];
        yield 'bad type' => [$advice(['type' => 'sometimes']), 'aop.aspects[0].advice[0]: "type" must be one of before, after, after-returning, after-throwing, around, \'sometimes\' given'];
        yield 'missing method' => [$advice(['method' => null]), '"method" (the advice method of '.PlainAspect::class.') is required'];
        yield 'unknown method' => [$advice(['method' => 'nope']), 'has no method "nope"'];
        yield 'private method' => [$advice(['method' => 'hidden']), 'must be public and non-static'];
        yield 'static method' => [$advice(['method' => 'statically']), 'must be public and non-static'];
        yield 'both pointcut kinds' => [$advice(['pointcut-ref' => 'x']), 'give exactly one of "pointcut" and "pointcut-ref"'];
        yield 'no pointcut' => [$advice(['pointcut' => null]), 'give exactly one of "pointcut" and "pointcut-ref"'];
        yield 'empty pointcut' => [$advice(['pointcut' => ' ']), '"pointcut" must be a non-empty expression string'];
        yield 'unknown pointcut-ref' => [$advice(['pointcut' => null, 'pointcut-ref' => 'missing']), '"pointcut-ref" => "missing" is not a configured pointcut (known: none)'];
        yield 'bad pointcut-ref' => [$advice(['pointcut' => null, 'pointcut-ref' => 'a b']), '"pointcut-ref" must be a pointcut name or Class::name'];
        yield 'returning on before' => [$advice(['returning' => 'r']), '"returning" is only valid for after-returning advice, this is before'];
        yield 'throwing on before' => [$advice(['throwing' => 'e']), '"throwing" is only valid for after-throwing advice'];
        yield 'bad returning name' => [$advice(['type' => 'after-returning', 'returning' => '1x']), '"returning" must be the name of an advice parameter'];
        yield 'bad arg-names' => [$advice(['arg-names' => ['nonsense']]), 'arg-names mentions "nonsense", but '.PlainAspect::class.'::before() has the parameters [jp]'];
        yield 'unknown advice key' => [$advice(['order' => 1]), 'aop.aspects[0].advice[0]: unknown key(s) "order"'];
        yield 'advice not a list' => [['aspects' => [['class' => PlainAspect::class, 'advice' => ['type' => 'before']]]], 'aop.aspects[0].advice: expected a list, a map given'];
        yield 'syntax error in pointcut' => [$advice(['pointcut' => 'execution(']), 'aop.aspects[0] ('.PlainAspect::class.')'];
        yield 'unbindable parameter' => [$advice(['method' => 'withArgument', 'type' => 'around']), 'Cannot bind parameter $amount'];
        yield 'around needs a proceeding join point' => [$advice(['type' => 'around']), 'Around advice '.PlainAspect::class.'::before must declare'];
        yield 'returning without parameter' => [$advice(['type' => 'after-returning', 'returning' => 'missing']), 'declares returning: "missing" but has no parameter $missing'];
        yield 'throwing without parameter' => [$advice(['type' => 'after-throwing', 'throwing' => 'missing']), 'declares throwing: "missing" but has no parameter $missing'];
        yield 'bad shared pointcut name' => [['pointcuts' => ['a-b' => 'x']], 'aop.pointcuts: "a-b" is not a valid pointcut name'];
        yield 'bad shared pointcut expression' => [['pointcuts' => ['p' => 'bogus(']], 'aop.pointcuts.p: invalid pointcut "bogus("'];
        yield 'empty shared pointcut' => [['pointcuts' => ['p' => '']], 'aop.pointcuts.p: the expression must be a non-empty string'];
        yield 'bad declare-parents' => [$aspect(['declare-parents' => [['types-matching' => 'A', 'implement-interface' => PlainAspect::class, 'default-impl' => DescribableImpl::class]]]), '"'.PlainAspect::class.'" is not an interface'];
        yield 'declare-parents impl missing' => [$aspect(['declare-parents' => [['types-matching' => 'A', 'implement-interface' => Describable::class, 'default-impl' => 'No\\Such\\Impl']]]), 'default implementation "No\\Such\\Impl" does not exist'];
        yield 'declare-parents missing key' => [$aspect(['declare-parents' => [['types-matching' => 'A']]]), '"implement-interface" is required'];
        yield 'declare-parents impl mismatch' => [$aspect(['declare-parents' => [['types-matching' => 'A', 'implement-interface' => Describable::class, 'default-impl' => PlainAspect::class]]]), 'does not implement'];
        yield 'advisor without advice' => [['advisors' => [['pointcut' => 'x']]], 'aop.advisors[0]: give exactly one of "advice" and "advice-ref"'];
        yield 'advisor advice not an object' => [['advisors' => [['advice' => 'x', 'pointcut' => self::ALL]]], '"advice" must be an advice object, string given'];
        yield 'advisor advice-ref without factory' => [['advisors' => [['advice-ref' => 'x', 'pointcut' => self::ALL]]], '"advice-ref" => "x" needs an $aspectFactory'];
        yield 'advisor with unsupported advice' => [['advisors' => [['advice' => new AuditAdvice(), 'pointcut' => self::ALL]]], 'No advisor adapter supports advice of type '.AuditAdvice::class];
        yield 'advisor with a bad pointcut' => [['advisors' => [['advice' => new BeforeOnly(), 'pointcut' => 'execution(']]], 'aop.advisors[0]: invalid pointcut "execution("'];
        yield 'advisor with unknown ref' => [['advisors' => [['advice' => new BeforeOnly(), 'pointcut-ref' => 'nope']]], 'aop.advisors[0]: "pointcut-ref" => "nope" is not a configured pointcut'];
        yield 'duplicate advisor id' => [['advisors' => [['id' => 'a', 'advice' => new BeforeOnly(), 'pointcut' => self::ALL], ['id' => 'a', 'advice' => new BeforeOnly(), 'pointcut' => self::ALL]]], 'advisor id "a" is used twice'];
        yield 'bad advisor order' => [['advisors' => [['advice' => new BeforeOnly(), 'pointcut' => self::ALL, 'order' => 'x']]], '"order" must be an integer'];
        yield 'invalid aspect pointcut name' => [$aspect(['pointcuts' => ['a-b' => 'x']]), 'aop.aspects[0].pointcuts: "a-b" is not a valid pointcut name'];
        yield 'declare-parents not a list' => [$aspect(['declare-parents' => ['k' => 1]]), 'aop.aspects[0].declare-parents: expected a list, a map given'];
        yield 'arg-names not a list' => [$advice(['arg-names' => 'jp']), 'aop.aspects[0].advice[0].arg-names: expected a list, string given'];
        yield 'empty ref' => [['aspects' => [['ref' => '']]], 'aop.aspects[0]: "ref" must be a non-empty string'];
        yield 'empty advice-ref' => [['advisors' => [['advice-ref' => '', 'pointcut' => self::ALL]]], 'aop.advisors[0]: "advice-ref" must be a non-empty string'];
        yield 'aspect id in the label' => [['aspects' => [['id' => 'audit', 'class' => 'No\\Such']]], 'aop.aspects[0] (audit): aspect class "No\\Such" does not exist'];
        yield 'known pointcuts are listed by name' => [$advice(['pointcut' => null, 'pointcut-ref' => 'missing']) + ['pointcuts' => ['one' => 'x', 'two' => 'y']], '"pointcut-ref" => "missing" is not a configured pointcut (known: one, two)'];
        yield 'pointcut-ref with leading garbage' => [$advice(['pointcut' => null, 'pointcut-ref' => 'a b::c']), '"pointcut-ref" must be a pointcut name or Class::name'];
        yield 'pointcut-ref with trailing garbage' => [$advice(['pointcut' => null, 'pointcut-ref' => 'Foo::bar baz']), '"pointcut-ref" must be a pointcut name or Class::name'];
        yield 'bad returning name with a valid prefix' => [$advice(['type' => 'after-returning', 'returning' => 'r-x']), '"returning" must be the name of an advice parameter'];
        yield 'whitespace shared pointcut' => [['pointcuts' => ['p' => '  ']], 'aop.pointcuts.p: the expression must be a non-empty string'];
        yield 'declare-parents without default-impl' => [$aspect(['declare-parents' => [['types-matching' => 'A', 'implement-interface' => Describable::class]]]), 'aop.aspects[0].declare-parents[0]: "default-impl" is required'];
        yield 'declare-parents unknown key' => [$aspect(['declare-parents' => [['types-matching' => 'A', 'implement-interface' => Describable::class, 'default-impl' => DescribableImpl::class, 'extra' => 1]]]), 'aop.aspects[0].declare-parents[0]: unknown key(s) "extra"'];
        yield 'advisor unknown key' => [['advisors' => [['advice' => new BeforeOnly(), 'pointcut' => self::ALL, 'extra' => 1]]], 'aop.advisors[0]: unknown key(s) "extra"'];
        yield 'advisor id taken by an aspect advice' => [['aspects' => [['class' => PlainAspect::class, 'advice' => [['type' => 'before', 'pointcut' => self::ALL, 'method' => 'before']]]], 'advisors' => [['id' => PlainAspect::class.'::before', 'advice' => new BeforeOnly(), 'pointcut' => self::ALL]]], 'advisor id "'.PlainAspect::class.'::before" is used twice'];
        yield 'bad advisor id' => [['advisors' => [['id' => '', 'advice' => new BeforeOnly(), 'pointcut' => self::ALL]]], '"id" must be a non-empty string'];
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('invalid')]
    public function rejectsInvalidConfigurationsWithAClearMessage(array $config, string $message): void
    {
        try {
            ConfigLoader::load($config);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame(0, $e->getCode());
        }
    }

    #[Test]
    public function typeNamesAreCaseInsensitiveAndAcceptUnderscores(): void
    {
        $result = ConfigLoader::load(self::config(
            ['type' => 'BEFORE', 'pointcut' => self::ALL, 'method' => 'before'],
            ['type' => 'After_Returning', 'pointcut' => self::ALL, 'method' => 'returned', 'returning' => 'result'],
        ));

        self::assertSame([AdviceType::BEFORE, AdviceType::AFTER_RETURNING], array_map(static fn (Advisor $a): AdviceType => $a->adviceType(), $result->aspectAdvisors()));
    }

    #[Test]
    public function namesAreTrimmedAndLeadingBackslashesIgnored(): void
    {
        $result = ConfigLoader::load([
            'pointcuts' => ['all' => self::ALL],
            'aspects' => [[
                'class' => '\\'.PlainAspect::class,
                'advice' => [['type' => 'before', 'pointcut-ref' => ' all ', 'method' => 'before']],
                'declare-parents' => [['types-matching' => 'A', 'implement-interface' => '\\'.Describable::class, 'default-impl' => '\\'.DescribableImpl::class]],
            ]],
        ]);

        self::assertSame(PlainAspect::class, $result->metadata()[0]->aspectClass);
        self::assertSame('all()', $result->metadata()[0]->advices[0]->expression);
        self::assertSame(DescribableImpl::class, $result->metadata()[0]->introductions[0]->defaultImpl);
    }

    #[Test]
    public function factoryResultsMustBeObjects(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('the aspect factory returned string for "bean", expected an object');

        ConfigLoader::load(['aspects' => [['ref' => 'bean']]], static fn (string $ref): string => 'nope'); // @phpstan-ignore argument.type
    }

    #[Test]
    public function aMissingReferenceInADeclaredPointcutIsReportedWithItsAspect(): void
    {
        try {
            ConfigLoader::load(self::config(['type' => 'before', 'pointcut' => 'unknownRef()', 'method' => 'before']));
            self::fail();
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('aop.aspects[0] ('.PlainAspect::class.')', $e->getMessage());
            self::assertStringContainsString('Unknown named pointcut "'.PlainAspect::class.'::unknownRef()"', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function configurationAdvisorsResolveOnlyTopLevelNamedPointcuts(): void
    {
        $resolver = new ArrayNamedPointcutResolver(['balance' => 'execution(* AOP\Tests\Fixtures\Api\Account::getBalance(..))']);

        self::assertSame('execution(* AOP\Tests\Fixtures\Api\Account::getBalance(..))', $resolver->resolve(null, 'balance', PlainAspect::class));
        try {
            $resolver->resolve(Account::class, 'balance', PlainAspect::class);
            self::fail('A class-qualified reference must be rejected.');
        } catch (PointcutSyntaxException $e) {
            self::assertStringContainsString('can only reference the top-level named pointcuts', $e->getMessage());
        }
        $this->expectException(PointcutSyntaxException::class);
        $this->expectExceptionMessage('Unknown named pointcut "nope()"');
        $resolver->resolve(null, 'nope', PlainAspect::class);
    }
}
