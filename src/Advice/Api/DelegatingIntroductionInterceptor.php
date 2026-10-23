<?php

declare(strict_types=1);

namespace AOP\Advice\Api;

use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\ProceedingJoinPointInterface;

/**
 * Introduces the interfaces of a delegate object: calls of their methods run on the delegate (Spring's
 * DelegatingIntroductionInterceptor).
 *
 * Used as a plain around interceptor it routes executions of methods that are declared by one of the introduced
 * interfaces to the delegate (with the call's arguments) and lets every other call proceed. With the engine's
 * introduction support (contract section 6) the engine creates the methods; use `introducedInterfaces()` to
 * register them. A delegate returning itself yields the advised target instead (fluent introductions).
 */
class DelegatingIntroductionInterceptor implements IntroductionInterceptorInterface
{
    /** @var list<class-string> */
    private readonly array $interfaces;

    /**
     * @param list<class-string>|null $interfaces the interfaces to introduce; default: every interface of the delegate
     */
    public function __construct(private readonly object $delegate, ?array $interfaces = null)
    {
        $this->interfaces = $interfaces ?? array_values(class_implements($delegate) ?: []);
        foreach ($this->interfaces as $interface) {
            if (!$delegate instanceof $interface) {
                throw new \InvalidArgumentException(sprintf('The delegate %s does not implement the interface %s.', $delegate::class, $interface));
            }
        }
    }

    public function getDelegate(): object
    {
        return $this->delegate;
    }

    /**
     * @return list<class-string>
     */
    public function introducedInterfaces(): array
    {
        return $this->interfaces;
    }

    public function implementsInterface(string $interface): bool
    {
        return in_array(ltrim($interface, '\\'), $this->interfaces, true);
    }

    public function invoke(ProceedingJoinPointInterface $invocation): mixed
    {
        $signature = $invocation->getSignature();
        if (!$signature instanceof MethodSignature || !$this->isIntroducedMethod($signature->methodName)) {
            return $invocation->proceed();
        }

        $result = $this->delegate->{$signature->methodName}(...$invocation->getArgs());

        return $result === $this->delegate ? ($invocation->getTarget() ?? $result) : $result;
    }

    private function isIntroducedMethod(string $method): bool
    {
        foreach ($this->interfaces as $interface) {
            if (method_exists($interface, $method)) {
                return true;
            }
        }

        return false;
    }
}
