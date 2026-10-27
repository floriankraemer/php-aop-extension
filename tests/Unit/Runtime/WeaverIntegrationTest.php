<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Attribute\Configurable;
use AOP\JoinPoint\JoinPointKind;
use AOP\TargetSource\HotSwappableTargetSource;
use AOP\Tests\Fixtures\Api\BeforeOnly;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\PlainConfigAspect;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class WeaverIntegrationTest extends RuntimeTestCase
{
    #[Test]
    public function fromConfigRegistersAspectsAndRemoveAspectDropsThem(): void
    {
        $weaver = Weaver::fromConfig(['aspects' => [[
            'class' => PlainConfigAspect::class,
            'advice' => [['type' => 'before', 'pointcut' => 'execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..))', 'method' => 'log']],
        ]]], null, $this->engine);
        $weaver->enable();
        $service = new TargetService();

        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['config:pub', 'pub'], Log::$entries);

        $weaver->removeAspect(PlainConfigAspect::class);
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);
    }

    #[Test]
    public function fromConfigRegistersProgrammaticAdvisors(): void
    {
        $weaver = Weaver::fromConfig(['advisors' => [[
            'advice' => new BeforeOnly('configured'),
            'pointcut' => 'execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..))',
        ]]], null, $this->engine);
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['configured', 'pub'], Log::$entries);
    }

    #[Test]
    public function targetSourceStandInsRunOnTheCurrentTarget(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->enable();
        $source = new HotSwappableTargetSource(new TargetService('first'));
        $standIn = $weaver->targetSource(TargetService::class, $source);

        self::assertSame('pub:x', $this->engine->call($standIn, 'pub', ['x']));
        self::assertSame(['pub'], Log::$entries);
        self::assertSame('pub:y', $this->engine->call(new TargetService(), 'pub', ['y']), 'other instances are not rerouted');
    }

    #[Test]
    public function configurableAddsTheConfigurableAdvisor(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->configurable(static function (object $object, Configurable $attribute): void {
        });

        self::assertSame(['configurable'], array_map(static fn ($a): string => $a->id, $weaver->advisors()));
    }

    #[Test]
    public function aParentConstructorExecutionIsNotOutermost(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $flags = [];
        $weaver->addAdvisor(new \AOP\Advisor\Advisor('c', null, 1, new KindPointcut([JoinPointKind::CONSTRUCTOR_EXECUTION]), new Trace('c', static function ($invocation) use (&$flags) {
            $flags[] = $invocation->isOutermost();

            return $invocation->proceed();
        })));
        $weaver->enable();
        $decide = (new \ReflectionProperty($this->engine, 'matcher'))->getValue($this->engine);
        $dispatch = (new \ReflectionProperty($this->engine, 'dispatcher'))->getValue($this->engine);
        self::assertIsCallable($decide);
        self::assertIsCallable($dispatch);
        $object = (new \ReflectionClass(TargetService::class))->newInstanceWithoutConstructor();
        $descriptor = ['kind' => 'constructor-execution', 'class' => TargetService::class, 'scope' => TargetService::class, 'name' => '__construct', 'static' => false, 'internal' => false, 'caller' => null];

        self::assertSame(1, $decide($descriptor));
        $dispatch($descriptor + ['outermost' => true], $object, [], static fn (array $a): null => null);
        $dispatch($descriptor + ['outermost' => false], $object, [], static fn (array $a): null => null);

        self::assertSame([true, false], $flags);
    }

    #[Test]
    public function initializationJoinPointsPassTheNewObjectAsThis(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new \AOP\Advisor\Advisor('i', null, 1, new KindPointcut([JoinPointKind::INITIALIZATION]), $trace = new Trace('i')));
        $weaver->enable();
        $dispatch = (new \ReflectionProperty($this->engine, 'dispatcher'))->getValue($this->engine);
        self::assertIsCallable($dispatch);
        $object = new TargetService();

        $dispatch(['kind' => 'initialization', 'class' => TargetService::class, 'scope' => TargetService::class, 'name' => '__construct', 'static' => false, 'internal' => false, 'caller' => null], $object, [], static fn (array $a): null => null);

        self::assertSame($object, $trace->seen[0]->getThis());
    }
}
