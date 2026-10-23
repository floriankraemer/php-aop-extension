<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * What is known about a join point without running it (AspectJ's `thisJoinPointStaticPart`).
 */
final readonly class StaticPart
{
    public function __construct(
        public JoinPointKind $kind,
        public SignatureInterface $signature,
        public ?SourceLocation $sourceLocation = null,
    ) {
    }

    /**
     * Example: method-execution(UserService::find(..)).
     */
    public function toShortString(): string
    {
        return sprintf('%s(%s)', $this->kind->value, $this->signature->toShortString());
    }

    public function toLongString(): string
    {
        return sprintf('%s(%s)', $this->kind->value, $this->signature->toLongString());
    }
}
