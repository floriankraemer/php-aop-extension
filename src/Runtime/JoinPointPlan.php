<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\SignatureInterface;
use AOP\JoinPoint\SourceLocation;
use AOP\JoinPoint\StaticPart;

/**
 * Everything the dispatcher decided about one (kind, class, scope, name, service, caller function):
 * built once, cached, and reused for every call.
 *
 * @internal
 */
final readonly class JoinPointPlan
{
    /**
     * @param list<MethodInterceptorInterface> $executionChain   starts with the FrameInterceptor; empty when nothing applies
     * @param list<MethodInterceptorInterface> $callChain        the same for the derived call join point
     * @param list<string>                     $executionArounds ids of the Around advisors in the execution chain
     * @param list<string>                     $callArounds
     * @param list<\ReflectionParameter>       $parameters       declared parameters (empty for fields and handlers)
     */
    public function __construct(
        public JoinPointKind $executionKind,
        public ?JoinPointKind $callKind,
        public SignatureInterface $signature,
        public StaticPart $executionPart,
        public ?StaticPart $callPart,
        public ?StaticPart $enclosing,
        public ?SourceLocation $executionLocation,
        public array $executionChain,
        public array $callChain,
        public array $executionArounds,
        public array $callArounds,
        public bool $normalizes,
        public array $parameters,
        public ?\ReflectionType $returnType,
        public ?string $declaringClass,
    ) {
    }

    /**
     * Nothing to do for this join point: the original runs directly.
     */
    public function isEmpty(): bool
    {
        return [] === $this->executionChain && [] === $this->callChain;
    }
}
