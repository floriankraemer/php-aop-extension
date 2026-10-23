<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

final readonly class FunctionSignature implements SignatureInterface
{
    public function __construct(
        public string $functionName,
        public ?\ReflectionFunction $function = null,
    ) {
    }

    public function getName(): string
    {
        return $this->functionName;
    }

    public function getDeclaringTypeName(): ?string
    {
        return null;
    }

    /**
     * Example: fmt(..).
     */
    public function toShortString(): string
    {
        return sprintf('%s(..)', SignatureNames::shortName($this->functionName));
    }

    /**
     * Example: App\Util\fmt(int, ...string).
     */
    public function toLongString(): string
    {
        return sprintf('%s(%s)', $this->functionName, SignatureNames::parameterList($this->function?->getParameters() ?? []));
    }
}
