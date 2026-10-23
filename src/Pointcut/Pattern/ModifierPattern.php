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

    /**
     * Plain functions have no visibility, so a modifier excludes them.
     */
    public function matches(\ReflectionFunctionAbstract $function): bool
    {
        if (!$function instanceof \ReflectionMethod) {
            return null === $this->modifier;
        }
        $method = $function;

        return match ($this->modifier) {
            'public' => $method->isPublic(),
            'protected' => $method->isProtected(),
            default => true,
        };
    }
}
