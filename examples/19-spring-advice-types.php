<?php

declare(strict_types=1);

namespace Demo\SpringAdvice;

require __DIR__.'/../vendor/autoload.php';

use AOP\Advice\Api\AfterReturningAdviceInterface;
use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Advice\Api\MethodInterceptorInterface;
use AOP\Advice\Api\ThrowsAdviceInterface;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

final class Greeter
{
    public function greet(string $name): string
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('a name is required');
        }

        return "hello $name";
    }
}

// Around advice (Spring's MethodInterceptor): wraps the call and decides whether it proceeds.
final class Timing implements MethodInterceptorInterface
{
    public function invoke(ProceedingJoinPointInterface $invocation): mixed
    {
        echo "around: enter {$invocation->getSignature()->getName()}\n";
        $result = $invocation->proceed();
        echo "around: leave\n";

        return $result;
    }
}

// Before advice: runs before the call, cannot change it.
final class Announce implements MethodBeforeAdviceInterface
{
    public function before(JoinPointInterface $joinPoint): void
    {
        echo "before: argument is {$joinPoint->getArgs()[0]}\n";
    }
}

// After-returning advice: sees the result, cannot change it.
final class Record implements AfterReturningAdviceInterface
{
    public function afterReturning(mixed $returnValue, JoinPointInterface $joinPoint): void
    {
        echo "after returning: $returnValue\n";
    }
}

// Throws advice: one afterThrowing* method per exception type; the most specific type wins.
final class FailureReport implements ThrowsAdviceInterface
{
    public function afterThrowing(\Throwable $e): void
    {
        echo 'afterThrowing (any): '.$e->getMessage()."\n";
    }

    public function afterThrowingInvalid(\InvalidArgumentException $e): void
    {
        echo 'afterThrowing (invalid argument): '.$e->getMessage()."\n";
    }
}

$weaver = Weaver::fromAspects([]);
$pointcut = 'execution(* Demo\SpringAdvice\Greeter::greet(..))';
$weaver->addAdvisor((new DefaultPointcutAdvisor($pointcut, new Timing(), 1, 'timing'))->toAdvisor());
$weaver->addAdvisor((new DefaultPointcutAdvisor($pointcut, new Announce(), 2, 'announce'))->toAdvisor());
$weaver->addAdvisor((new DefaultPointcutAdvisor($pointcut, new Record(), 3, 'record'))->toAdvisor());
$weaver->addAdvisor((new DefaultPointcutAdvisor($pointcut, new FailureReport(), 4, 'failure'))->toAdvisor());
$weaver->enable();

$greeter = new Greeter();
echo $greeter->greet('Ada')."\n";

echo "--- empty name\n";
try {
    $greeter->greet('');
} catch (\InvalidArgumentException $e) {
    echo 'caller got: '.$e->getMessage()."\n";
}

/* Expected output:
around: enter greet
before: argument is Ada
after returning: hello Ada
around: leave
hello Ada
--- empty name
around: enter greet
before: argument is 
afterThrowing (invalid argument): a name is required
caller got: a name is required
*/
