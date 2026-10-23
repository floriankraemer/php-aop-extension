<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;
use AOP\Tests\Fixtures\Log;

/**
 * A programmatic around interceptor that logs "label>" and "label<" and remembers the join points it saw.
 */
final class Trace implements MethodInterceptorInterface
{
    /** @var list<MethodInvocation> */
    public array $seen = [];

    /** @var list<array<int|string, mixed>> */
    public array $args = [];

    /**
     * @param (\Closure(MethodInvocation): mixed)|null $replace runs instead of proceeding when given
     */
    public function __construct(private readonly string $label, private readonly ?\Closure $replace = null)
    {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $this->seen[] = $invocation;
        $this->args[] = $invocation->getArgs();
        Log::add($this->label.'>');
        try {
            return null === $this->replace ? $invocation->proceed() : ($this->replace)($invocation);
        } finally {
            Log::add($this->label.'<');
        }
    }
}
