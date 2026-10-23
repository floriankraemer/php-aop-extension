<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * Everything about a join point except its arguments and the chain: the constant part of a MethodInvocation.
 * `$this` cannot name a parameter, so the executing object is called `$thisObject`.
 */
final readonly class JoinPointData
{
    /**
     * @param object|null $thisObject the executing object; for call kinds the caller's object
     * @param object|null $target     the object the member belongs to; null for static members and functions
     */
    public function __construct(
        public JoinPointKind $kind,
        public ?object $thisObject,
        public ?object $target,
        public SignatureInterface $signature,
        public StaticPart $staticPart,
        public ?StaticPart $enclosingStaticPart = null,
        public ?SourceLocation $sourceLocation = null,
    ) {
    }
}
