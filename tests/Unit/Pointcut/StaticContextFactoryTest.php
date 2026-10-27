<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\CatchClauseSignature;
use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\SignatureInterface;
use AOP\JoinPoint\SourceLocation;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\StaticContextFactory;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaticContextFactoryTest extends TestCase
{
    private static function jp(JoinPointKind $kind, SignatureInterface $signature, ?object $thisObject = null, ?object $target = null, ?StaticPart $enclosing = null, ?SourceLocation $location = null): MethodInvocation
    {
        return new MethodInvocation(
            new JoinPointData($kind, $thisObject, $target, $signature, new StaticPart($kind, $signature), $enclosing, $location),
            [],
            [],
            static fn (array $a): null => null,
        );
    }

    #[Test]
    public function rebuildsAMethodExecutionFromItsSignatureAndTarget(): void
    {
        $callee = new Callee();
        $signature = MethodSignature::fromReflection(new \ReflectionMethod($callee, 'find'));

        $ctx = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::METHOD_EXECUTION, $signature, $callee, $callee));

        self::assertNotNull($ctx);
        self::assertSame(JoinPointKind::METHOD_EXECUTION, $ctx->kind);
        self::assertSame(Callee::class, $ctx->targetClass?->getName());
        self::assertSame('find', $ctx->function?->getName());
        self::assertNull($ctx->caller);
        self::assertNull($ctx->serviceName);
    }

    #[Test]
    public function rebuildsAConstructorExecution(): void
    {
        $callee = new Callee();
        $constructor = new \ReflectionMethod(Callee::class, '__construct');

        $ctx = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::CONSTRUCTOR_EXECUTION, new ConstructorSignature(Callee::class, $constructor), $callee, $callee));

        self::assertSame($constructor->getName(), $ctx?->function?->getName());
        self::assertSame(Callee::class, $ctx->targetClass?->getName());
    }

    #[Test]
    public function rebuildsAFunctionByNameWithoutAnObjectOrTargetClass(): void
    {
        $ctx = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature('strlen')));

        self::assertSame('strlen', $ctx?->function?->getName());
        self::assertNull($ctx->targetClass);

        $unknown = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature('aop_tests_no_such_function')));
        self::assertNotNull($unknown);
        self::assertNull($unknown->function);
    }

    #[Test]
    public function rebuildsAFieldAccessAndFallsBackToThisWhenThereIsNoTarget(): void
    {
        $callee = new Callee();

        $ctx = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::FIELD_GET, new FieldSignature(Callee::class, 'count'), $callee));

        self::assertSame('count', $ctx?->property?->getName());
        self::assertSame(Callee::class, $ctx->targetClass?->getName());

        $dynamic = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::FIELD_SET, new FieldSignature(Callee::class, 'dynamic'), $callee));
        self::assertNotNull($dynamic);
        self::assertNull($dynamic->property, 'undeclared properties have no reflection');
    }

    #[Test]
    public function rebuildsAHandlerWithTheCaughtType(): void
    {
        $callee = new Callee();

        $ctx = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::EXCEPTION_HANDLER, new CatchClauseSignature(\RuntimeException::class, Callee::class), $callee));

        self::assertSame(\RuntimeException::class, $ctx?->caughtType?->getName());
        self::assertSame(Callee::class, $ctx->targetClass?->getName());

        $noObject = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::EXCEPTION_HANDLER, new CatchClauseSignature('Aop\Tests\NoSuchException', Callee::class)));
        self::assertNotNull($noObject);
        self::assertNull($noObject->caughtType);
        self::assertNull($noObject->targetClass, 'a handler without an object has no target class');
    }

    #[Test]
    public function rebuildsTheCallerFromTheEnclosingStaticPart(): void
    {
        $callee = new Callee();
        $user = new UserService();
        $call = MethodSignature::fromReflection(new \ReflectionMethod($callee, 'find'));
        $enclosingMethod = new StaticPart(JoinPointKind::METHOD_EXECUTION, MethodSignature::fromReflection(new \ReflectionMethod($user, 'findUser')));

        $fromMethod = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::METHOD_CALL, $call, $user, $callee, $enclosingMethod, new SourceLocation('/src/a.php', 3)));

        self::assertSame(UserService::class, $fromMethod?->caller?->class?->getName(), 'the caller object wins');
        self::assertSame('findUser', $fromMethod->caller->function?->getName());
        self::assertSame('/src/a.php', $fromMethod->caller->file);

        $enclosingConstructor = new StaticPart(JoinPointKind::CONSTRUCTOR_EXECUTION, new ConstructorSignature(Callee::class, new \ReflectionMethod(Callee::class, '__construct')));
        $fromConstructor = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::METHOD_CALL, $call, null, $callee, $enclosingConstructor));
        self::assertSame(Callee::class, $fromConstructor?->caller?->class?->getName(), 'no caller object: the declaring class');
        self::assertSame('__construct', $fromConstructor->caller->function?->getName());

        $enclosingFunction = new StaticPart(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature('strlen'));
        $fromFunction = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::METHOD_CALL, $call, null, $callee, $enclosingFunction));
        self::assertSame('strlen', $fromFunction?->caller?->function?->getName());
        self::assertNull($fromFunction->caller->class);

        $enclosingUnknown = new StaticPart(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature('aop_tests_no_such_function'));
        $fromUnknown = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::METHOD_CALL, $call, null, $callee, $enclosingUnknown));
        self::assertNull($fromUnknown?->caller?->function);

        $enclosingOther = new StaticPart(JoinPointKind::EXCEPTION_HANDLER, new CatchClauseSignature(\RuntimeException::class));
        $fromHandler = StaticContextFactory::fromJoinPoint(self::jp(JoinPointKind::METHOD_CALL, $call, null, $callee, $enclosingOther));
        self::assertNull($fromHandler?->caller?->function, 'only code with a function has one');
    }
}
