<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\ControlFlowInterface;
use AOP\Pointcut\EmptyControlFlow;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeMatchContextTest extends TestCase
{
    #[Test]
    public function exposesTheJoinPointAndStaticContext(): void
    {
        $target = new UserService();
        $joinPoint = Jp::invocation($target, 'findUser', [42]);
        $static = Jp::ctx(UserService::class, 'findUser');

        $context = new RuntimeMatchContext($joinPoint, $static);

        self::assertSame($joinPoint, $context->joinPoint);
        self::assertSame($static, $context->staticContext);
        self::assertSame($target, $context->this());
        self::assertSame($target, $context->target());
        self::assertSame([42], $context->args());
        self::assertSame($static->function, $context->function());
        self::assertSame($static->targetClass, $context->targetClass());
    }

    #[Test]
    public function controlFlowDefaultsToEmpty(): void
    {
        $context = Jp::runtime(new UserService(), 'findUser');

        self::assertInstanceOf(EmptyControlFlow::class, $context->controlFlow);
        self::assertSame([], $context->controlFlow->stack());
    }

    #[Test]
    public function usesTheGivenControlFlow(): void
    {
        $joinPoint = Jp::invocation(new UserService(), 'findUser');
        $flow = new class($joinPoint) implements ControlFlowInterface {
            public function __construct(private readonly \AOP\JoinPoint\JoinPointInterface $joinPoint)
            {
            }

            public function stack(): array
            {
                return [$this->joinPoint];
            }
        };

        $context = new RuntimeMatchContext($joinPoint, Jp::ctx(UserService::class, 'findUser'), $flow);

        self::assertSame([$joinPoint], $context->controlFlow->stack());
    }

    #[Test]
    public function nonMethodJoinPointsHaveNoFunctionOrTargetClass(): void
    {
        $static = new \AOP\Pointcut\StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null);
        $context = new RuntimeMatchContext(Jp::invocation(new UserService(), 'findUser'), $static);

        self::assertNull($context->function());
        self::assertNull($context->targetClass());
    }

    #[Test]
    public function boundIsEmptyInitially(): void
    {
        self::assertSame([], Jp::runtime(new UserService(), 'findUser')->bound());
    }

    #[Test]
    public function bindStoresValuesByName(): void
    {
        $context = Jp::runtime(new UserService(), 'findUser');
        $user = new UserService();

        $context->bind('user', $user);
        $context->bind('id', 42);

        self::assertSame(['user' => $user, 'id' => 42], $context->bound());
    }
}
