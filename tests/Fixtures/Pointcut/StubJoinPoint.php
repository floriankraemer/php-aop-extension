<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\JoinPoint\CatchClauseSignature;
use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\SignatureInterface;
use AOP\JoinPoint\SourceLocation;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\OutermostAwareInterface;

/**
 * A join point with every part chosen by the test, optionally telling whether a constructor is the outermost one.
 */
final class StubJoinPoint implements JoinPointInterface, OutermostAwareInterface
{
    /**
     * @param array<int|string, mixed> $args
     */
    public function __construct(
        private readonly JoinPointKind $kind,
        private readonly SignatureInterface $signature,
        private readonly ?object $thisObject = null,
        private readonly ?object $target = null,
        private readonly array $args = [],
        private readonly ?StaticPart $enclosing = null,
        private readonly bool $outermost = true,
    ) {
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function method(object $target, string $method, JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION, array $args = [], ?object $thisObject = null, ?StaticPart $enclosing = null): self
    {
        $reflection = new \ReflectionMethod($target, $method);

        return new self($kind, MethodSignature::fromReflection($reflection), $kind->isCall() ? $thisObject : ($reflection->isStatic() ? null : $target), $reflection->isStatic() ? null : $target, $args, $enclosing);
    }

    public static function constructor(object $target, JoinPointKind $kind = JoinPointKind::CONSTRUCTOR_EXECUTION, bool $outermost = true): self
    {
        return new self($kind, new ConstructorSignature($target::class, (new \ReflectionClass($target))->getConstructor()), $target, $target, [], null, $outermost);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function function(string $name, array $args = [], ?StaticPart $enclosing = null): self
    {
        return new self(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature($name, new \ReflectionFunction($name)), null, null, $args, $enclosing);
    }

    public static function field(object $object, string $property, JoinPointKind $kind = JoinPointKind::FIELD_GET, mixed $value = null, ?StaticPart $enclosing = null): self
    {
        $reflection = new \ReflectionProperty($object, $property);

        return new self($kind, new FieldSignature($object::class, $property, $reflection), $object, $object, JoinPointKind::FIELD_SET === $kind ? [$value] : [], $enclosing);
    }

    public static function handler(\Throwable $exception, ?object $thisObject = null, ?StaticPart $enclosing = null): self
    {
        return new self(JoinPointKind::EXCEPTION_HANDLER, new CatchClauseSignature($exception::class, null === $thisObject ? null : $thisObject::class), $thisObject, null, [$exception], $enclosing);
    }

    public static function enclosingMethod(object|string $class, string $method): StaticPart
    {
        return new StaticPart(JoinPointKind::METHOD_EXECUTION, MethodSignature::fromReflection(new \ReflectionMethod($class, $method)));
    }

    public function getKind(): JoinPointKind
    {
        return $this->kind;
    }

    public function getThis(): ?object
    {
        return $this->thisObject;
    }

    public function getTarget(): ?object
    {
        return $this->target;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getSignature(): SignatureInterface
    {
        return $this->signature;
    }

    public function getStaticPart(): StaticPart
    {
        return new StaticPart($this->kind, $this->signature);
    }

    public function getEnclosingStaticPart(): ?StaticPart
    {
        return $this->enclosing;
    }

    public function getSourceLocation(): ?SourceLocation
    {
        return null;
    }

    public function toShortString(): string
    {
        return $this->getStaticPart()->toShortString();
    }

    public function toLongString(): string
    {
        return $this->getStaticPart()->toLongString();
    }

    public function isOutermost(): bool
    {
        return $this->outermost;
    }
}
