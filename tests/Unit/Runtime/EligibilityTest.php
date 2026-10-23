<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\JoinPoint\JoinPointKind;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\AbstractThing;
use AOP\Tests\Fixtures\Runtime\Aspect\LogAspect;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\Mode;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class EligibilityTest extends RuntimeTestCase
{
    private function matcher(): callable
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('all', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION, JoinPointKind::FUNCTION_EXECUTION, JoinPointKind::CONSTRUCTOR_EXECUTION]), new Trace('t')));
        $weaver->enable();
        $matcher = (new \ReflectionProperty($this->engine, 'matcher'))->getValue($this->engine);
        self::assertIsCallable($matcher);

        return $matcher;
    }

    /**
     * @return array<string, mixed>
     */
    private static function method(string $class, string $name, ?string $scope = null): array
    {
        return ['kind' => 'method-execution', 'class' => $class, 'scope' => $scope ?? $class, 'name' => $name, 'static' => false, 'internal' => false, 'caller' => null];
    }

    #[Test]
    public function ordinaryMethodsAreAdvised(): void
    {
        $matcher = $this->matcher();

        self::assertSame(1, $matcher(self::method(TargetService::class, 'hidden')));
        self::assertSame(1, $matcher(self::method(TargetService::class, 'make')));
        self::assertSame(1, $matcher(self::method(TargetService::class, 'sealed')));
        self::assertSame(1, $matcher(self::method(TargetService::class, '__construct') + ['kind' => 'constructor-execution']));
    }

    #[Test]
    public function abstractMethodsAreNeverAdvised(): void
    {
        self::assertSame(0, $this->matcher()(self::method(AbstractThing::class, 'run')));
    }

    #[Test]
    public function aspectClassesEnumsAndAnonymousClassesAreNeverAdvised(): void
    {
        $matcher = $this->matcher();
        $anonymous = new class {
            public function m(): void
            {
            }
        };

        self::assertSame(0, $matcher(self::method(LogAspect::class, 'before')));
        self::assertSame(0, $matcher(self::method(Mode::class, 'label')));
        self::assertSame(0, $matcher(self::method($anonymous::class, 'm')));
    }

    #[Test]
    public function theRuntimeNamespaceIsNeverAdvised(): void
    {
        $matcher = $this->matcher();

        self::assertSame(0, $matcher(self::method(Weaver::class, 'enable')));
        self::assertSame(0, $matcher(['kind' => 'function-execution', 'class' => null, 'scope' => null, 'name' => 'AOP\fake', 'static' => false, 'internal' => false, 'caller' => null]));
    }

    #[Test]
    public function internalFunctionsAreEligible(): void
    {
        self::assertSame(1, $this->matcher()(['kind' => 'function-execution', 'class' => null, 'scope' => null, 'name' => 'str_repeat', 'static' => false, 'internal' => true, 'caller' => null]));
    }

    #[Test]
    public function aBrokenDescriptorYieldsNoDecisionInsteadOfAnError(): void
    {
        $matcher = $this->matcher();

        self::assertSame(0, $matcher(self::method(TargetService::class, 'doesNotExist')));
        self::assertSame(0, $matcher(['kind' => 'nonsense']));
        self::assertSame([], Log::$entries);
    }
}
