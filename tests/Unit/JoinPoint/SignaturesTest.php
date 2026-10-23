<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\JoinPoint;

use AOP\JoinPoint\AdviceSignature;
use AOP\JoinPoint\CatchClauseSignature;
use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\SignatureInterface;
use AOP\JoinPoint\SourceLocation;
use AOP\JoinPoint\StaticPart;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Functions\ConstructedService;
use AOP\Tests\Fixtures\Service\UserService;
use AOP\Tests\Fixtures\Targets\KindShapes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignaturesTest extends TestCase
{
    #[Test]
    public function methodSignatureImplementsTheInterface(): void
    {
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));

        self::assertInstanceOf(SignatureInterface::class, $signature);
        self::assertSame('findUser', $signature->getName());
        self::assertSame(UserService::class, $signature->getDeclaringTypeName());
    }

    #[Test]
    public function constructorSignature(): void
    {
        $signature = new ConstructorSignature(ConstructedService::class, new \ReflectionMethod(ConstructedService::class, '__construct'));

        self::assertSame('__construct', $signature->getName());
        self::assertSame(ConstructedService::class, $signature->getDeclaringTypeName());
        self::assertSame('ConstructedService::__construct(..)', $signature->toShortString());
        self::assertSame(ConstructedService::class.'::__construct(string)', $signature->toLongString());
    }

    #[Test]
    public function constructorSignatureWithoutAConstructor(): void
    {
        $signature = new ConstructorSignature(KindShapes::class);

        self::assertNull($signature->constructor);
        self::assertSame('KindShapes::__construct(..)', $signature->toShortString());
        self::assertSame(KindShapes::class.'::__construct()', $signature->toLongString());
    }

    #[Test]
    public function functionSignature(): void
    {
        $signature = new FunctionSignature('str_replace', new \ReflectionFunction('str_replace'));

        self::assertSame('str_replace', $signature->getName());
        self::assertNull($signature->getDeclaringTypeName());
        self::assertSame('str_replace(..)', $signature->toShortString());
        self::assertStringStartsWith('str_replace(', $signature->toLongString());
    }

    #[Test]
    public function namespacedFunctionSignature(): void
    {
        $signature = new FunctionSignature('App\Util\fmt');

        self::assertSame('App\Util\fmt', $signature->getName());
        self::assertSame('fmt(..)', $signature->toShortString());
        self::assertSame('App\Util\fmt()', $signature->toLongString());
    }

    #[Test]
    public function fieldSignature(): void
    {
        $signature = new FieldSignature(KindShapes::class, 'count', new \ReflectionProperty(KindShapes::class, 'count'));

        self::assertSame('count', $signature->getName());
        self::assertSame(KindShapes::class, $signature->getDeclaringTypeName());
        self::assertSame('KindShapes::$count', $signature->toShortString());
        self::assertSame('int '.KindShapes::class.'::$count', $signature->toLongString());
        self::assertSame(KindShapes::class.'::$dynamic', (new FieldSignature(KindShapes::class, 'dynamic'))->toLongString());
    }

    #[Test]
    public function catchClauseSignature(): void
    {
        $signature = new CatchClauseSignature(\RuntimeException::class, UserService::class);

        self::assertSame(\RuntimeException::class, $signature->getName());
        self::assertSame(UserService::class, $signature->getDeclaringTypeName());
        self::assertSame('catch(RuntimeException)', $signature->toShortString());
        self::assertSame('catch(RuntimeException)', $signature->toLongString());

        $namespaced = new CatchClauseSignature('App\Error\Boom');
        self::assertNull($namespaced->getDeclaringTypeName());
        self::assertSame('catch(Boom)', $namespaced->toShortString());
        self::assertSame('catch(App\Error\Boom)', $namespaced->toLongString());
    }

    #[Test]
    public function adviceSignatureIsAMethodSignature(): void
    {
        $signature = AdviceSignature::fromReflection(new \ReflectionMethod(ProbeAspect::class, 'plain'));

        self::assertInstanceOf(MethodSignature::class, $signature);
        self::assertSame(ProbeAspect::class, $signature->className);
        self::assertSame('plain', $signature->methodName);
        self::assertSame('plain', $signature->getName());
        self::assertSame('adviceexecution(ProbeAspect::plain(..))', $signature->toShortString());
        self::assertStringStartsWith('adviceexecution('.ProbeAspect::class.'::plain(', $signature->toLongString());
    }

    #[Test]
    public function sourceLocation(): void
    {
        $location = new SourceLocation('/app/a.php', 7, 'App\A', 'run');

        self::assertSame('/app/a.php', $location->file);
        self::assertSame(7, $location->line);
        self::assertSame('App\A', $location->class);
        self::assertSame('run', $location->function);
        self::assertSame('App\A::run (/app/a.php:7)', $location->toString());
        self::assertSame('run (/app/a.php)', (new SourceLocation('/app/a.php', null, null, 'run'))->toString());
        self::assertSame('<unknown>', (new SourceLocation())->toString());
        self::assertSame('/app/a.php:3', (new SourceLocation('/app/a.php', 3))->toString());
    }

    #[Test]
    public function staticPart(): void
    {
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));
        $location = new SourceLocation('/a.php', 1);
        $part = new StaticPart(JoinPointKind::METHOD_EXECUTION, $signature, $location);

        self::assertSame(JoinPointKind::METHOD_EXECUTION, $part->kind);
        self::assertSame($signature, $part->signature);
        self::assertSame($location, $part->sourceLocation);
        self::assertSame('method-execution(UserService::findUser(..))', $part->toShortString());
        self::assertSame('method-execution('.UserService::class.'::findUser(int, ...string))', $part->toLongString());
        self::assertNull((new StaticPart(JoinPointKind::METHOD_CALL, $signature))->sourceLocation);
    }
}
