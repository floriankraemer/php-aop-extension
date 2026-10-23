<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Interceptor;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\Interceptor\RuntimeGuardInterceptor;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\ControlFlowInterface;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor;
use AOP\Tests\Fixtures\Pointcut\StubPointcut;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeGuardInterceptorTest extends TestCase
{
    /**
     * @param list<mixed> $args
     */
    private function invokeChain(PointcutInterface $pointcut, array $args, ?\Closure $inner = null): mixed
    {
        $target = new UserService();
        $static = Jp::ctx(UserService::class, 'findUser');
        $guard = new RuntimeGuardInterceptor(
            $pointcut,
            new class($inner) implements MethodInterceptorInterface {
                public function __construct(private readonly ?\Closure $inner)
                {
                }

                public function invoke(MethodInvocation $invocation): mixed
                {
                    ($this->inner ?? static fn (): null => null)($invocation);

                    return $invocation->proceed();
                }
            },
            $static,
        );

        return Jp::invocation($target, 'findUser', $args, [$guard], static fn (array $a): string => 'target')->proceed();
    }

    #[Test]
    public function skipsInnerInterceptorWhenRuntimeMatchFails(): void
    {
        $called = false;
        $pointcut = (new PointcutExpressionCompiler())->compile('args(string, ..)');

        $result = $this->invokeChain($pointcut, [1], function () use (&$called): void {
            $called = true;
        });

        self::assertFalse($called);
        self::assertSame('target', $result);
    }

    #[Test]
    public function runsInnerInterceptorWithBoundValuesWhenMatching(): void
    {
        $bound = null;
        $pointcut = (new PointcutExpressionCompiler())->compile('args(id, ..)', ['id' => 'int']);

        $result = $this->invokeChain($pointcut, [7, 'flag'], function (MethodInvocation $invocation) use (&$bound): void {
            $bound = $invocation->getBoundArguments();
        });

        self::assertSame(['id' => 7], $bound);
        self::assertSame('target', $result);
    }

    #[Test]
    public function receivesJoinPointStaticContextAndArguments(): void
    {
        $pointcut = new class implements PointcutInterface {
            public ?RuntimeMatchContext $ctx = null;

            public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
            {
                return true;
            }

            public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
            {
                return true;
            }

            public function isRuntime(): bool
            {
                return true;
            }

            public function matchesRuntime(RuntimeMatchContext $ctx): bool
            {
                $this->ctx = $ctx;

                return true;
            }

            public function bindings(): array
            {
                return [];
            }

            public function isCallerSensitive(): bool
            {
                return false;
            }
        };

        $this->invokeChain($pointcut, [3]);

        self::assertNotNull($pointcut->ctx);
        self::assertSame('findUser', $pointcut->ctx->function()?->getName());
        self::assertSame([3], $pointcut->ctx->args());
        self::assertInstanceOf(UserService::class, $pointcut->ctx->target());
        self::assertInstanceOf(UserService::class, $pointcut->ctx->this());
        self::assertSame(UserService::class, $pointcut->ctx->targetClass()?->getName());
        self::assertSame('findUser', $pointcut->ctx->staticContext->function?->getName());
        self::assertSame(JoinPointKind::METHOD_EXECUTION, $pointcut->ctx->joinPoint->getKind());
    }

    #[Test]
    public function boundArgumentsSurviveFurtherProceedCalls(): void
    {
        $invocation = Jp::invocation(new UserService(), 'findUser', [1]);

        self::assertSame([], $invocation->getBoundArguments());
        $with = $invocation->withBoundArguments(['a' => 1]);
        self::assertSame(['a' => 1], $with->getBoundArguments());
        self::assertSame([], $invocation->getBoundArguments());
    }

    #[Test]
    public function handsTheControlFlowToThePointcut(): void
    {
        $joinPoint = Jp::invocation(new UserService(), 'findUser', [1]);
        $flow = new class($joinPoint) implements ControlFlowInterface {
            public function __construct(private readonly JoinPointInterface $joinPoint)
            {
            }

            public function stack(): array
            {
                return [$this->joinPoint];
            }
        };
        $pointcut = new class extends StubPointcut {
            /** @var list<JoinPointInterface>|null */
            public ?array $stack = null;

            public function __construct()
            {
                parent::__construct(true, true, true);
            }

            public function matchesRuntime(RuntimeMatchContext $ctx): bool
            {
                $this->stack = $ctx->controlFlow->stack();

                return true;
            }
        };
        $guard = new RuntimeGuardInterceptor($pointcut, new PassThroughInterceptor(), Jp::ctx(UserService::class, 'findUser'), $flow);

        Jp::invocation(new UserService(), 'findUser', [], [$guard], static fn (array $a): null => null)->proceed();

        self::assertSame([$joinPoint], $pointcut->stack);
    }
}
