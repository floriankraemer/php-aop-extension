<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\Api\IntroductionInterceptorInterface;
use AOP\Advisor\Advisor;
use AOP\Pointcut\Api\ApiPointcut;
use AOP\Pointcut\Api\ClassFilterInterface;
use AOP\Pointcut\Api\StaticMethodMatcherPointcut;
use AOP\Pointcut\Api\TrueClassFilter;
use AOP\Pointcut\PointcutInterface;

/**
 * Introduces the interfaces of an introduction interceptor (Spring's DefaultIntroductionAdvisor).
 *
 * As a programmatic advisor it routes the executions of the introduced interfaces' methods through the
 * interceptor, so a delegating interceptor answers them. With engine introductions (contract section 6) the
 * Weaver additionally calls `Engine::setIntroductions()` for `introducedInterfaces()`; this advisor does not.
 */
final class DefaultIntroductionAdvisor
{
    private readonly PointcutInterface $pointcut;

    /**
     * @param list<class-string> $interfaces
     */
    public function __construct(
        private readonly IntroductionInterceptorInterface $interceptor,
        private readonly array $interfaces,
        ClassFilterInterface $classFilter = TrueClassFilter::INSTANCE,
        private readonly int $order = PHP_INT_MAX,
        private readonly ?string $id = null,
    ) {
        foreach ($interfaces as $interface) {
            if (!$interceptor->implementsInterface($interface)) {
                throw new \InvalidArgumentException(sprintf('The introduction interceptor %s does not implement the interface %s.', $interceptor::class, $interface));
            }
        }
        $this->pointcut = new ApiPointcut($classFilter, (new class($interfaces) extends StaticMethodMatcherPointcut {
            /** @param list<class-string> $interfaces */
            public function __construct(private readonly array $interfaces)
            {
            }

            public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
            {
                foreach ($this->interfaces as $interface) {
                    if (method_exists($interface, $function->getName())) {
                        return true;
                    }
                }

                return false;
            }
        })->getMethodMatcher());
    }

    /**
     * @return list<class-string>
     */
    public function introducedInterfaces(): array
    {
        return $this->interfaces;
    }

    public function toAdvisor(): Advisor
    {
        return (new DefaultPointcutAdvisor($this->pointcut, $this->interceptor, $this->order, $this->id))->toAdvisor();
    }
}
