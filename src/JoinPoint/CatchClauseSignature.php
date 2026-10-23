<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

final readonly class CatchClauseSignature implements SignatureInterface
{
    /**
     * @param string      $caughtType     the class named in the catch clause
     * @param string|null $declaringClass the class whose code contains the clause, when known
     */
    public function __construct(
        public string $caughtType,
        public ?string $declaringClass = null,
    ) {
    }

    public function getName(): string
    {
        return $this->caughtType;
    }

    public function getDeclaringTypeName(): ?string
    {
        return $this->declaringClass;
    }

    /**
     * Example: catch(RuntimeException).
     */
    public function toShortString(): string
    {
        return sprintf('catch(%s)', SignatureNames::shortName($this->caughtType));
    }

    public function toLongString(): string
    {
        return sprintf('catch(%s)', $this->caughtType);
    }
}
