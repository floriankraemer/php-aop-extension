<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Binding\ServiceInterface;
use AOP\Tests\Fixtures\Binding\Tagged;

/**
 * Records the values bound by this/@annotation/@target/@within/@args, including nullable advice parameter types.
 */
#[Aspect]
final class BindingProbeAspect
{
    /** @var list<array{string, mixed}> */
    public array $calls = [];

    #[Before('execution(* AOP\Tests\Fixtures\Binding\TaggedService::own(..)) && this(proxy)')]
    public function nullableThis(?ServiceInterface $proxy): void
    {
        $this->calls[] = ['this', $proxy];
    }

    #[Before('execution(* AOP\Tests\Fixtures\Binding\TaggedService::own(..)) && @annotation(tag)')]
    public function nullableAnnotation(?Tagged $tag): void
    {
        $this->calls[] = ['@annotation', $tag?->label];
    }

    #[Before('execution(* AOP\Tests\Fixtures\Binding\**::*(..)) && @target(tag)')]
    public function atTarget(Tagged $tag): void
    {
        $this->calls[] = ['@target', $tag->label];
    }

    #[Before('execution(* AOP\Tests\Fixtures\Binding\**::inherited(..)) && @within(tag)')]
    public function atWithin(Tagged $tag): void
    {
        $this->calls[] = ['@within', $tag->label];
    }

    #[Before('execution(* AOP\Tests\Fixtures\Binding\**::accept(..)) && @args(tag)')]
    public function nullableAtArgs(?Tagged $tag): void
    {
        $this->calls[] = ['@args', $tag?->label];
    }
}
