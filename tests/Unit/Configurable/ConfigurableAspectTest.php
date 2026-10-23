<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Configurable;

use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Attribute\Configurable;
use AOP\Configurable\ConfigurableAspect;
use AOP\Configurable\ConfigurablePointcut;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\ConfiguredChild;
use AOP\Tests\Fixtures\Api\ConfiguredService;
use AOP\Tests\Fixtures\Api\ConstructorLessConfigured;
use AOP\Tests\Fixtures\Api\EarlyConfiguredService;
use AOP\Tests\Fixtures\Api\Run;
use AOP\Tests\Fixtures\Api\UnconfiguredService;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Log;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigurableAspectTest extends TestCase
{
    /** @var list<array{object, Configurable}> */
    private array $configured = [];

    protected function setUp(): void
    {
        Log::reset();
        $this->configured = [];
    }

    private function aspect(): ConfigurableAspect
    {
        return new ConfigurableAspect(function (object $instance, Configurable $attribute): void {
            $this->configured[] = [$instance, $attribute];
            Log::add('configure');
            if (property_exists($instance, 'injected')) {
                $instance->injected = $attribute->service ?? 'default';
            }
        });
    }

    /**
     * @return list<MethodInterceptorInterface>
     */
    private function chain(Advisor $advisor): array
    {
        self::assertNotNull($advisor->interceptor);

        return [$advisor->interceptor];
    }

    /**
     * Creates the object the way the engine does: allocated, then its constructor runs through the advisor chain.
     *
     * @param class-string $class
     * @param list<mixed>  $args
     */
    private function construct(Advisor $advisor, string $class, array $args = []): object
    {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        Run::call([$advisor], $object, '__construct', $args, kind: JoinPointKind::CONSTRUCTOR_EXECUTION);

        return $object;
    }

    #[Test]
    public function configuresAfterTheConstructor(): void
    {
        $object = $this->construct($this->aspect()->toAdvisor(), ConfiguredService::class, ['named']);

        self::assertSame(['ctor:null', 'configure'], Log::$entries);
        self::assertInstanceOf(ConfiguredService::class, $object);
        self::assertSame('configured', $object->injected, 'the attribute reached the configurer');
        self::assertSame('named', $object->name);
        self::assertCount(1, $this->configured);
        self::assertSame($object, $this->configured[0][0]);
        self::assertSame('configured', $this->configured[0][1]->service);
        self::assertFalse($this->configured[0][1]->preConstruction);
    }

    #[Test]
    public function configuresBeforeTheConstructorWhenAsked(): void
    {
        $object = $this->construct($this->aspect()->toAdvisor(), EarlyConfiguredService::class);

        self::assertSame(['configure', 'ctor:default'], Log::$entries, 'the constructor already sees the injected value');
        self::assertInstanceOf(EarlyConfiguredService::class, $object);
        self::assertTrue($this->configured[0][1]->preConstruction);
        self::assertTrue($this->configured[0][1]->autowire);
    }

    #[Test]
    public function configuresOnceAroundTheOutermostConstructor(): void
    {
        $advisor = $this->aspect()->toAdvisor();
        $chain = $this->chain($advisor);
        $child = (new \ReflectionClass(ConfiguredChild::class))->newInstanceWithoutConstructor();
        $parentConstructor = new \ReflectionMethod(ConfiguredService::class, '__construct');

        // ConfiguredChild::__construct() calls parent::__construct(): both are constructor-execution join points
        $inner = static fn (): MethodInvocation => new MethodInvocation(Jp::data($child, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION, outermost: false), ['child'], $chain, static fn (array $a): mixed => $parentConstructor->invokeArgs($child, $a));
        $outer = new MethodInvocation(Jp::data($child, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION), [], $chain, static function () use ($inner): null {
            Log::add('child-before-parent');
            $inner()->proceed();
            Log::add('child-after-parent');

            return null;
        });

        $outer->proceed();

        self::assertSame(['child-before-parent', 'ctor:null', 'child-after-parent', 'configure'], Log::$entries);
        self::assertCount(1, $this->configured);
        self::assertSame('configured', $this->configured[0][1]->service, 'a subclass inherits the attribute of its parent');
    }

    #[Test]
    public function preConstructionConfigurationHappensOnTheOutermostEntryOnly(): void
    {
        $advisor = $this->aspect()->toAdvisor();
        $chain = $this->chain($advisor);
        $object = (new \ReflectionClass(EarlyConfiguredService::class))->newInstanceWithoutConstructor();
        $inner = new MethodInvocation(Jp::data($object, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION, outermost: false), [], $chain, static fn (array $a): null => null);
        $outer = new MethodInvocation(Jp::data($object, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION), [], $chain, static function () use ($inner): null {
            Log::add('outer-body');
            $inner->proceed();

            return null;
        });

        $outer->proceed();

        self::assertSame(['configure', 'outer-body'], Log::$entries);
    }

    #[Test]
    public function aFailingConstructorSkipsPostConstructionConfigurationAndAllowsAFreshAttempt(): void
    {
        $advisor = $this->aspect()->toAdvisor();
        $object = (new \ReflectionClass(ConfiguredService::class))->newInstanceWithoutConstructor();
        $data = Jp::data($object, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION);

        try {
            (new MethodInvocation($data, [], $this->chain($advisor), static fn (array $a): never => throw new \DomainException('ctor failed')))->proceed();
            self::fail();
        } catch (\DomainException) {
        }
        self::assertSame([], $this->configured);

        (new MethodInvocation($data, [], $this->chain($advisor), static fn (array $a): null => null))->proceed();
        self::assertCount(1, $this->configured, 'the failed attempt left no state behind');
    }

    #[Test]
    public function everyObjectIsConfiguredOnItsOwn(): void
    {
        $advisor = $this->aspect()->toAdvisor();

        $a = $this->construct($advisor, ConfiguredService::class);
        $b = $this->construct($advisor, ConfiguredService::class);

        self::assertCount(2, $this->configured);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function constructorLessClassesAreConfiguredThroughInitialization(): void
    {
        $advisor = $this->aspect()->toAdvisor();
        $object = new ConstructorLessConfigured();
        $signature = new ConstructorSignature(ConstructorLessConfigured::class);
        $kind = JoinPointKind::INITIALIZATION;
        $invocation = new MethodInvocation(new JoinPointData($kind, $object, $object, $signature, new StaticPart($kind, $signature)), [], $this->chain($advisor), static fn (array $a): null => null);

        $invocation->proceed();

        self::assertSame('default', $object->injected);
        self::assertSame([[$object, $this->configured[0][1]]], $this->configured);
    }

    #[Test]
    public function aMissingObjectIsLeftAlone(): void
    {
        $advisor = $this->aspect()->toAdvisor();
        $signature = new ConstructorSignature(ConstructorLessConfigured::class);
        $kind = JoinPointKind::INITIALIZATION;
        $invocation = new MethodInvocation(new JoinPointData($kind, null, null, $signature, new StaticPart($kind, $signature)), [], $this->chain($advisor), static fn (array $a): string => 'proceeded');

        self::assertSame('proceeded', $invocation->proceed());
        self::assertSame([], $this->configured);
    }

    #[Test]
    public function classesWithoutTheAttributeAreNotMatched(): void
    {
        $pointcut = new ConfigurablePointcut();
        $constructor = new \ReflectionMethod(UnconfiguredService::class, '__construct');

        self::assertFalse($pointcut->matchesClass(new \ReflectionClass(UnconfiguredService::class), Jp::ctx(UnconfiguredService::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertFalse($pointcut->matchesMethod($constructor, Jp::ctx(UnconfiguredService::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertFalse($pointcut->matchesClass(null, new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null)));
    }

    #[Test]
    public function onlyConstructionKindsAreMatched(): void
    {
        $pointcut = new ConfigurablePointcut();
        $class = new \ReflectionClass(ConfiguredService::class);
        $constructor = new \ReflectionMethod(ConfiguredService::class, '__construct');

        self::assertTrue($pointcut->matchesClass($class, Jp::ctx(ConfiguredService::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertTrue($pointcut->matchesMethod($constructor, Jp::ctx(ConfiguredService::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertTrue($pointcut->matchesClass(new \ReflectionClass(ConstructorLessConfigured::class), new StaticMatchContext(JoinPointKind::INITIALIZATION, new \ReflectionClass(ConstructorLessConfigured::class))));
        self::assertTrue($pointcut->matchesClass(new \ReflectionClass(ConfiguredChild::class), Jp::ctx(ConfiguredChild::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION)), 'subclass');
        self::assertFalse($pointcut->matchesClass($class, Jp::ctx(ConfiguredService::class, '__construct', JoinPointKind::CONSTRUCTOR_CALL)));
        self::assertFalse($pointcut->matchesClass($class, Jp::ctx(ConfiguredService::class, '__construct', JoinPointKind::METHOD_EXECUTION)));
        self::assertFalse($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime(Jp::runtime(new Account(), 'getBalance')));
        self::assertSame([], $pointcut->bindings());
        self::assertFalse($pointcut->isCallerSensitive());
    }

    #[Test]
    public function theAdvisorIsOutermostByDefaultAndCarriesAnId(): void
    {
        $advisor = $this->aspect()->toAdvisor();
        $custom = (new ConfigurableAspect(static fn (object $o, Configurable $c): null => null, 5))->toAdvisor('my-configurable');

        self::assertSame(PHP_INT_MIN, $advisor->order);
        self::assertSame('configurable', $advisor->id);
        self::assertSame(AdviceType::AROUND, $advisor->adviceType());
        self::assertNull($advisor->advice);
        self::assertSame(5, $custom->order);
        self::assertSame('my-configurable', $custom->id);
    }

    #[Test]
    public function theAttributeIsFoundOnTheNearestClass(): void
    {
        self::assertSame('configured', ConfigurableAspect::attributeFor(new \ReflectionClass(ConfiguredChild::class))?->service);
        self::assertNull(ConfigurableAspect::attributeFor(new \ReflectionClass(UnconfiguredService::class)));
    }

    #[Test]
    public function theAttributeHasSensibleDefaults(): void
    {
        $attribute = new Configurable();

        self::assertFalse($attribute->autowire);
        self::assertFalse($attribute->preConstruction);
        self::assertNull($attribute->service);
        self::assertSame(\Attribute::TARGET_CLASS, (new \ReflectionClass(Configurable::class))->getAttributes(\Attribute::class)[0]->getArguments()[0]);
    }
}
