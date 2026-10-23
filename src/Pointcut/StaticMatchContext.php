<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * The "shadow" of a join point: everything known without running the code.
 */
final class StaticMatchContext
{
    /**
     * @param \ReflectionClass<object>|null $targetClass          runtime class; null for functions
     * @param \ReflectionClass<object>|null $caughtType           exception handlers: the caught class
     * @param list<class-string>            $introducedInterfaces interfaces the class implements through introductions
     */
    public function __construct(
        public readonly JoinPointKind $kind,
        public readonly ?\ReflectionClass $targetClass,
        public readonly ?\ReflectionFunctionAbstract $function = null,
        public readonly ?\ReflectionProperty $property = null,
        public readonly ?\ReflectionClass $caughtType = null,
        public readonly ?EnclosingContext $caller = null,
        public readonly ?string $serviceName = null,
        public readonly array $introducedInterfaces = [],
    ) {
    }
}
