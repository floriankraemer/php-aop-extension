<?php

declare(strict_types=1);

namespace Demo\Adapters;

require __DIR__.'/../vendor/autoload.php';

use AOP\Advice\Api\Interceptor\InterceptorBridge;
use AOP\Advice\Api\MethodInterceptorInterface;
use AOP\Advisor\Api\AdvisorAdapterInterface;
use AOP\Advisor\Api\AdvisorAdapterRegistry;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Interceptor\MethodInterceptorInterface as EngineInterceptor;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

// Your own advice type: not an interface of the library, just a class with a method.
final class Retry
{
    public function __construct(private readonly int $times)
    {
    }

    public function times(): int
    {
        return $this->times;
    }
}

// The around interceptor for Retry, written against the Spring-style interface.
final class RetryInterceptor implements MethodInterceptorInterface
{
    public function __construct(private readonly Retry $retry)
    {
    }

    public function invoke(ProceedingJoinPointInterface $invocation): mixed
    {
        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                return $invocation->proceed();
            } catch (\RuntimeException $e) {
                echo "attempt $attempt failed: {$e->getMessage()}\n";
                if ($attempt >= $this->retry->times()) {
                    throw $e;
                }
            }
        }
    }
}

// The adapter tells the registry when it applies and wraps the interceptor for the engine's chain.
final class RetryAdapter implements AdvisorAdapterInterface
{
    public function supportsAdvice(object $advice): bool
    {
        return $advice instanceof Retry;
    }

    public function getInterceptor(object $advice): EngineInterceptor
    {
        return new InterceptorBridge(new RetryInterceptor($advice));
    }
}

final class Gateway
{
    private int $calls = 0;

    public function fetch(string $url): string
    {
        if (++$this->calls < 3) {
            throw new \RuntimeException("timeout on $url");
        }

        return "200 OK from $url";
    }
}

$registry = new AdvisorAdapterRegistry();
$registry->registerAdvisorAdapter(new RetryAdapter());

$weaver = Weaver::fromAspects([]);
$weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\Adapters\Gateway::fetch(..))', new Retry(3), 1, 'retry', $registry))->toAdvisor());
$weaver->enable();

echo (new Gateway())->fetch('https://example.test')."\n";

/* Expected output:
attempt 1 failed: timeout on https://example.test
attempt 2 failed: timeout on https://example.test
200 OK from https://example.test
*/
