<?php

declare(strict_types=1);

namespace AOP\Configurable;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

/**
 * Configures an object once, around its outermost constructor.
 *
 * The runtime marks the outermost constructor in the join point descriptor (`outermost`), but the join point
 * model does not expose it. So the interceptor counts the constructors that are running on each object (a
 * `parent::__construct()` is a constructor-execution join point too) and acts when that count enters or leaves zero.
 *
 * @internal built by ConfigurableAspect
 */
final class ConfigurableInterceptor implements MethodInterceptorInterface
{
    /** @var \WeakMap<object, int> */
    private \WeakMap $running;

    public function __construct(private readonly ConfigurableAspect $aspect)
    {
        $this->running = new \WeakMap();
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        // For a constructor the executing object exists already; an `initialization` join point has it as `$this` too.
        $object = $invocation->getThis() ?? $invocation->getTarget();
        $attribute = null === $object ? null : ConfigurableAspect::attributeFor(new \ReflectionClass($object));
        if (null === $object || null === $attribute) {
            return $invocation->proceed();
        }

        $outermost = 0 === ($this->running[$object] ?? 0);
        if ($outermost && $attribute->preConstruction) {
            $this->aspect->configure($object, $attribute);
        }

        $this->running[$object] = ($this->running[$object] ?? 0) + 1;
        try {
            $result = $invocation->proceed();
        } finally {
            if (0 === --$this->running[$object]) {
                unset($this->running[$object]);
            }
        }

        if ($outermost && !$attribute->preConstruction) {
            $this->aspect->configure($object, $attribute);
        }

        return $result;
    }
}
