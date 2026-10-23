<?php

declare(strict_types=1);

namespace AOP\Configurable;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

/**
 * Configures an object once, around its outermost constructor.
 *
 * A `parent::__construct()` is a constructor-execution join point too, with the same object. The runtime marks it as not
 * outermost (`MethodInvocation::isOutermost()`, from `OutermostAwareInterface`), so only the constructor `new` called
 * configures the object; the inner ones just run. An `initialization` join point is outermost.
 *
 * @internal built by ConfigurableAspect
 */
final class ConfigurableInterceptor implements MethodInterceptorInterface
{
    public function __construct(private readonly ConfigurableAspect $aspect)
    {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        if (!$invocation->isOutermost()) {
            return $invocation->proceed();
        }
        // For a constructor the executing object exists already; an `initialization` join point has it as `$this` too.
        $object = $invocation->getThis() ?? $invocation->getTarget();
        $attribute = null === $object ? null : ConfigurableAspect::attributeFor(new \ReflectionClass($object));
        if (null === $object || null === $attribute) {
            return $invocation->proceed();
        }

        if ($attribute->preConstruction) {
            $this->aspect->configure($object, $attribute);
        }
        $result = $invocation->proceed();
        if (!$attribute->preConstruction) {
            $this->aspect->configure($object, $attribute);
        }

        return $result;
    }
}
