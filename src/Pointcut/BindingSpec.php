<?php

declare(strict_types=1);

namespace AOP\Pointcut;

final readonly class BindingSpec
{
    public function __construct(
        public string $name,
        public BindingKind $kind,
        public ?string $typeRestriction,
    ) {
    }

    /**
     * The restriction as a pattern for an object's class: `?` dropped (the bound value is never null when the
     * pointcut matches), null when it restricts nothing (`mixed`, `object` or no type).
     */
    public function classRestriction(): ?string
    {
        $type = null === $this->typeRestriction ? null : ltrim($this->typeRestriction, '?');

        return null === $type || in_array(strtolower($type), ['mixed', 'object'], true) ? null : $type;
    }
}
