<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Api\ApiPointcut;
use AOP\Pointcut\Api\ClassFilterInterface;
use AOP\Pointcut\Api\DynamicMethodMatcherPointcut;
use AOP\Pointcut\Api\MethodMatcherInterface;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Pointcut\Api\StaticMethodMatcherPointcut;
use AOP\Pointcut\Api\TrueClassFilter;
use AOP\Pointcut\Api\TrueMethodMatcher;
use AOP\Pointcut\Api\TruePointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\SavingsAccount;
use AOP\Tests\Fixtures\Jp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiPointcutTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function filter(string $class): ClassFilterInterface
    {
        return new class($class) implements ClassFilterInterface {
            /** @param class-string $class */
            public function __construct(private readonly string $class)
            {
            }

            public function matches(\ReflectionClass $class): bool
            {
                return $class->isSubclassOf($this->class) || $class->getName() === $this->class;
            }
        };
    }

    private function matcher(string $name): MethodMatcherInterface
    {
        return new class($name) implements MethodMatcherInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
            {
                return $function->getName() === $this->name;
            }

            public function isRuntime(): bool
            {
                return false;
            }

            public function matchesRuntime(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
            {
                return true;
            }
        };
    }

    #[Test]
    public function adaptsClassFilterAndMethodMatcher(): void
    {
        $pointcut = new ApiPointcut($this->filter(Account::class), $this->matcher('getBalance'));

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance'), new \ReflectionClass(Account::class)));
        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(SavingsAccount::class, 'getBalance'), new \ReflectionClass(SavingsAccount::class)));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'deposit')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(\ArrayObject::class, 'count')));
        self::assertFalse($pointcut->isRuntime());
        self::assertSame([], $pointcut->bindings());
        self::assertFalse($pointcut->isCallerSensitive());
    }

    #[Test]
    public function matchesMethodAppliesTheClassFilterToo(): void
    {
        // inside a union the class prefilter is not guaranteed to have run
        $pointcut = new ApiPointcut($this->filter(SavingsAccount::class), TrueMethodMatcher::INSTANCE);
        $ctx = Jp::ctx(Account::class, 'deposit');

        self::assertFalse($pointcut->matchesClass(new \ReflectionClass(Account::class), $ctx));
        self::assertFalse($pointcut->matchesMethod(new \ReflectionMethod(Account::class, 'deposit'), $ctx));
    }

    #[Test]
    public function onlyAppliesToMethodAndFunctionExecutionsByDefault(): void
    {
        $pointcut = new ApiPointcut(TrueClassFilter::INSTANCE, TrueMethodMatcher::INSTANCE);
        $method = new \ReflectionMethod(Account::class, 'getBalance');

        self::assertTrue($pointcut->matchesMethod($method, Jp::ctx(Account::class, 'getBalance')));
        self::assertFalse($pointcut->matchesMethod($method, Jp::ctx(Account::class, 'getBalance', JoinPointKind::METHOD_CALL)));
        self::assertFalse($pointcut->matchesMethod($method, Jp::ctx(Account::class, 'getBalance', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertFalse($pointcut->matchesMethod(null, Jp::ctx(Account::class)));

        $withConstructors = new ApiPointcut(TrueClassFilter::INSTANCE, TrueMethodMatcher::INSTANCE, [JoinPointKind::CONSTRUCTOR_EXECUTION]);
        self::assertTrue($withConstructors->matchesMethod($method, Jp::ctx(Account::class, 'getBalance', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertFalse($withConstructors->matchesMethod($method, Jp::ctx(Account::class, 'getBalance')));
    }

    #[Test]
    public function aClassFilterIsSkippedForFunctions(): void
    {
        $pointcut = new ApiPointcut($this->filter(Account::class), TrueMethodMatcher::INSTANCE);

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionFunction('strlen')));
    }

    #[Test]
    public function staticMethodMatcherPointcutsDecideOncePerMethod(): void
    {
        $pointcut = new class extends StaticMethodMatcherPointcut {
            public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
            {
                return str_starts_with($function->getName(), 'get');
            }
        };

        self::assertFalse($pointcut->isRuntime());
        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'setBalance')));
        self::assertFalse($pointcut->getMethodMatcher()->isRuntime());
        self::assertTrue($pointcut->getMethodMatcher()->matchesRuntime(new \ReflectionMethod(Account::class, 'getBalance'), null, []));
    }

    #[Test]
    public function staticMethodMatcherPointcutsCanBeRestrictedByAClassFilter(): void
    {
        $pointcut = (new class extends StaticMethodMatcherPointcut {
            public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
            {
                return true;
            }
        })->setClassFilter($this->filter(SavingsAccount::class));

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(SavingsAccount::class, 'interest')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
    }

    #[Test]
    public function kindsCanBeWidened(): void
    {
        $pointcut = (new class extends StaticMethodMatcherPointcut {
            public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
            {
                return true;
            }
        })->setKinds([JoinPointKind::CONSTRUCTOR_EXECUTION]);

        $method = new \ReflectionMethod(Account::class, 'getBalance');
        self::assertTrue($pointcut->matchesMethod($method, Jp::ctx(Account::class, 'getBalance', JoinPointKind::CONSTRUCTOR_EXECUTION)));
        self::assertFalse($pointcut->matchesMethod($method, Jp::ctx(Account::class, 'getBalance')));
    }

    #[Test]
    public function dynamicMethodMatcherPointcutsLookAtTheArguments(): void
    {
        $pointcut = new class extends DynamicMethodMatcherPointcut {
            public function matchesArguments(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
            {
                return ($args[0] ?? 0) > 10;
            }
        };
        $method = new \ReflectionMethod(Account::class, 'deposit');

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue(Pointcuts::matches($pointcut, $method, null, [50], new Account()));
        self::assertFalse(Pointcuts::matches($pointcut, $method, null, [5], new Account()));
        self::assertTrue($pointcut->getMethodMatcher()->isRuntime());
    }

    #[Test]
    public function theTrueConstantsMatchEverything(): void
    {
        $method = new \ReflectionMethod(Account::class, 'getBalance');
        $ctx = Jp::ctx(Account::class, 'getBalance');

        self::assertTrue(TrueClassFilter::INSTANCE->matches(new \ReflectionClass(Account::class)));
        self::assertTrue(TrueMethodMatcher::INSTANCE->matches($method, null));
        self::assertFalse(TrueMethodMatcher::INSTANCE->isRuntime());
        self::assertTrue(TruePointcut::INSTANCE->matchesClass(null, $ctx));
        self::assertTrue(TruePointcut::INSTANCE->matchesMethod(null, $ctx));
        self::assertFalse(TruePointcut::INSTANCE->isRuntime());
        self::assertTrue(TruePointcut::INSTANCE->matchesRuntime(Jp::runtime(new Account(), 'getBalance')));
        self::assertSame([], TruePointcut::INSTANCE->bindings());
        self::assertFalse(TruePointcut::INSTANCE->isCallerSensitive());
    }

    #[Test]
    public function exposesItsClassFilterAndMethodMatcher(): void
    {
        $filter = $this->filter(Account::class);
        $matcher = $this->matcher('getBalance');
        $pointcut = new ApiPointcut($filter, $matcher);

        self::assertSame($filter, $pointcut->getClassFilter());
        self::assertSame($matcher, $pointcut->getMethodMatcher());
        self::assertFalse($pointcut->requiresFieldInterception());
    }

    #[Test]
    public function theTrueMethodMatcherNeverNeedsARuntimeDecision(): void
    {
        $method = new \ReflectionMethod(Account::class, 'getBalance');

        self::assertFalse(TrueMethodMatcher::INSTANCE->isRuntime());
        self::assertTrue(TrueMethodMatcher::INSTANCE->matchesRuntime($method, new \ReflectionClass(Account::class), []));
    }

    #[Test]
    public function methodMatcherPointcutsExposeTheirClassFilterAndNeverNeedFieldInterception(): void
    {
        $pointcut = new class extends StaticMethodMatcherPointcut {
            public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
            {
                return true;
            }
        };
        self::assertSame(TrueClassFilter::INSTANCE, $pointcut->getClassFilter());
        self::assertFalse($pointcut->requiresFieldInterception());

        $filter = $this->filter(Account::class);
        self::assertSame($filter, $pointcut->setClassFilter($filter)->getClassFilter());
    }
}
