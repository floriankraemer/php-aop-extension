<?php

declare(strict_types=1);

namespace AOP\Advice\Api\Interceptor;

use AOP\Advice\Api\ThrowsAdviceInterface;
use AOP\Exception\AspectDefinitionException;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

/**
 * Dispatches a thrown exception to the `afterThrowing*` handler of the most specific type, then rethrows it.
 *
 * @see ThrowsAdviceInterface
 */
final class ThrowsAdviceBridge implements MethodInterceptorInterface
{
    /** @var array<string, array{string, bool}> exception type => [handler method, takes the join point] */
    private array $handlers = [];

    /**
     * @throws AspectDefinitionException when there is no handler or a handler has an unusable signature
     */
    public function __construct(private readonly ThrowsAdviceInterface $advice)
    {
        $class = new \ReflectionClass($advice);
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'afterThrowing') && !$method->isStatic()) {
                $this->register($method);
            }
        }
        if ([] === $this->handlers) {
            throw new AspectDefinitionException(sprintf('ThrowsAdvice %s needs at least one public method named afterThrowing*(\Throwable $e) or afterThrowing*(JoinPointInterface $joinPoint, \Throwable $e).', $class->getName()));
        }
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        try {
            return $invocation->proceed();
        } catch (\Throwable $e) {
            $handler = $this->handlerFor($e);
            if (null !== $handler) {
                [$method, $withJoinPoint] = $handler;
                $withJoinPoint ? $this->advice->{$method}($invocation, $e) : $this->advice->{$method}($e);
            }

            throw $e;
        }
    }

    /**
     * @return array{string, bool}|null
     */
    private function handlerFor(\Throwable $e): ?array
    {
        $type = $e::class;
        do {
            if (isset($this->handlers[$type])) {
                return $this->handlers[$type];
            }
            $type = get_parent_class($type);
        } while (false !== $type);

        foreach (class_implements($e) ?: [] as $interface) {
            if (isset($this->handlers[$interface])) {
                return $this->handlers[$interface];
            }
        }

        return $this->handlers[\Throwable::class] ?? null;
    }

    private function register(\ReflectionMethod $method): void
    {
        $where = sprintf('%s::%s()', $method->getDeclaringClass()->getName(), $method->getName());
        $parameters = $method->getParameters();
        $withJoinPoint = 2 === count($parameters);
        $exceptionParameter = end($parameters);
        if (false === $exceptionParameter || count($parameters) > 2) {
            throw new AspectDefinitionException(sprintf('ThrowsAdvice handler %s must take (\Throwable $e) or (JoinPointInterface $joinPoint, \Throwable $e).', $where));
        }
        if ($withJoinPoint) {
            $first = $parameters[0]->getType();
            if (!$first instanceof \ReflectionNamedType || !is_a(MethodInvocation::class, $first->getName(), true)) {
                throw new AspectDefinitionException(sprintf('The first parameter of ThrowsAdvice handler %s must be typed JoinPointInterface.', $where));
            }
        }

        $type = $exceptionParameter->getType();
        $names = match (true) {
            null === $type => [\Throwable::class],
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType => array_map(static fn (\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : '', $type->getTypes()),
            default => [],
        };
        if ([] === $names) {
            throw new AspectDefinitionException(sprintf('The exception parameter of ThrowsAdvice handler %s must be typed with exception classes.', $where));
        }
        foreach ($names as $name) {
            if (!is_a($name, \Throwable::class, true)) {
                throw new AspectDefinitionException(sprintf('The exception parameter of ThrowsAdvice handler %s must be a Throwable, "%s" is not.', $where, $name));
            }
            if (isset($this->handlers[$name])) {
                throw new AspectDefinitionException(sprintf('ThrowsAdvice handlers %s and %s() both handle %s.', $where, $this->handlers[$name][0], $name));
            }
            $this->handlers[$name] = [$method->getName(), $withJoinPoint];
        }
    }
}
