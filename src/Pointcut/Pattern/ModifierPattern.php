<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

final class ModifierPattern
{
    /**
     * @param 'public'|'protected'|null $modifier null = any
     */
    public function __construct(private readonly ?string $modifier)
    {
    }

    public function matches(\ReflectionMethod $method): bool
    {
        return match ($this->modifier) {
            'public' => $method->isPublic(),
            'protected' => $method->isProtected(),
            default => true,
        };
    }
}
