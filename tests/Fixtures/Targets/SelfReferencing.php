<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

class SelfReferencing extends SelfReferencingBase
{
    public function withSelf(self $other, ?self $maybe = null): static
    {
        return $this;
    }

    public function makeSelf(): self
    {
        return new self();
    }

    public function withParent(parent $base): parent
    {
        return $base;
    }

    public function unionSelf(self|int $v): self|int
    {
        return $v;
    }
}
