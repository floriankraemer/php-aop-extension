<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

interface JoinPointInterface
{
    public function getKind(): JoinPointKind;

    /**
     * The executing object; for call kinds the caller's object. Null for static code and functions.
     */
    public function getThis(): ?object;

    /**
     * The object the member belongs to; null for static members and functions.
     */
    public function getTarget(): ?object;

    /**
     * @return array<int|string, mixed>
     */
    public function getArgs(): array;

    public function getSignature(): SignatureInterface;

    public function getStaticPart(): StaticPart;

    /**
     * The code containing the join point (the caller), when known.
     */
    public function getEnclosingStaticPart(): ?StaticPart;

    /**
     * The call site, when known.
     */
    public function getSourceLocation(): ?SourceLocation;

    public function toShortString(): string;

    public function toLongString(): string;
}
