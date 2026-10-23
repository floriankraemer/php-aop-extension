<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Advice\AdviceType;
use AOP\Aspect\AspectInstanceResolverInterface;
use AOP\Binding\BindingPlan;
use AOP\Binding\ParameterBinder;
use AOP\Interceptor\AfterAdviceInterceptor;
use AOP\Interceptor\AfterReturningAdviceInterceptor;
use AOP\Interceptor\AfterThrowingAdviceInterceptor;
use AOP\Interceptor\AroundAdviceInterceptor;
use AOP\Interceptor\BeforeAdviceInterceptor;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\Interceptor\RuntimeGuardInterceptor;
use AOP\Pointcut\StaticMatchContext;

final class AdvisorChainFactory
{
    /** @var array<string, BindingPlan> */
    private array $plans = [];

    public function __construct(
        private readonly AdvisorRegistry $registry,
        private readonly AspectInstanceResolverInterface $aspects,
        private readonly ParameterBinder $binder,
    ) {
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<Advisor>
     */
    public function matchedAdvisors(\ReflectionClass $class, \ReflectionMethod $method, StaticMatchContext $ctx): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            static fn (Advisor $a): bool => $a->pointcut->matchesClass($class, $ctx) && $a->pointcut->matchesMethod($method, $ctx),
        ));
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<MethodInterceptorInterface>
     */
    public function chain(\ReflectionClass $class, \ReflectionMethod $method, StaticMatchContext $ctx): array
    {
        return $this->chainFor($this->matchedAdvisors($class, $method, $ctx), $ctx);
    }

    /**
     * Interceptors for advisors already matched statically, in that order.
     *
     * @param list<Advisor> $advisors
     *
     * @return list<MethodInterceptorInterface>
     */
    public function chainFor(array $advisors, StaticMatchContext $ctx): array
    {
        $chain = [];
        foreach ($advisors as $advisor) {
            $interceptor = $this->interceptor($advisor);
            $chain[] = $advisor->pointcut->isRuntime() ? new RuntimeGuardInterceptor($advisor->pointcut, $interceptor, $ctx) : $interceptor;
        }

        return $chain;
    }

    private function interceptor(Advisor $advisor): MethodInterceptorInterface
    {
        $advice = $advisor->advice;
        $plan = $this->plans[$advisor->id] ??= $this->binder->plan(new \ReflectionMethod($advice->aspectClass, $advice->methodName), $advice, $advisor->pointcut);
        $args = [$this->aspects, $advice->aspectClass, $advice->methodName, $plan, $advice->scope];

        return match ($advice->type) {
            AdviceType::BEFORE => new BeforeAdviceInterceptor(...$args),
            AdviceType::AFTER_RETURNING => new AfterReturningAdviceInterceptor(...$args),
            AdviceType::AFTER_THROWING => new AfterThrowingAdviceInterceptor(...$args),
            AdviceType::AFTER => new AfterAdviceInterceptor(...$args),
            AdviceType::AROUND => new AroundAdviceInterceptor(...$args),
        };
    }
}
