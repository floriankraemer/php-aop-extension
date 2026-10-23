<?php

declare(strict_types=1);

/*
 * Code advised by the end-to-end tests. It lives outside the AOP\ namespace on purpose: the engine never
 * intercepts classes in AOP\ nor calls made from code in AOP\, so tests cannot drive it from their own namespace.
 */

namespace AopE2E;

use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Aspect\AspectScope;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Configurable;
use AOP\Attribute\DeclareError;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;

final class Journal
{
    /** @var list<string> */
    public static array $entries = [];

    public static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    public static function reset(): void
    {
        self::$entries = [];
    }
}

class Service
{
    public function __construct(public string $name = 'service')
    {
        Journal::$entries[] = 'ctor';
    }

    public function pub(string $x): string
    {
        return $this->secret($x).'+'.self::counted().'+'.$this->sealed();
    }

    private function secret(string $x): string
    {
        return 'secret:'.$x;
    }

    protected function guarded(): string
    {
        return 'guarded';
    }

    public function viaGuarded(): string
    {
        return $this->guarded();
    }

    public static function counted(): string
    {
        return 'counted';
    }

    final public function sealed(): string
    {
        return 'sealed';
    }

    public function bump(int &$n): void
    {
        ++$n;
    }

    public function join(string $sep = ',', string ...$parts): string
    {
        return implode($sep, $parts);
    }

    public function count(): int
    {
        return 3;
    }
}

final class FinalService
{
    public function hello(): string
    {
        return 'hello';
    }
}

function shout(string $text, string $suffix = '!'): string
{
    return strtoupper($text).$suffix;
}

/**
 * Calls everything from code outside the AOP\ namespace, so the engine sees a caller it may intercept.
 */
final class Driver
{
    public function service(): Service
    {
        return new Service('made');
    }

    public function pub(Service $s, string $x): string
    {
        return $s->pub($x);
    }

    public function viaGuarded(Service $s): string
    {
        return $s->viaGuarded();
    }

    public function counted(): string
    {
        return Service::counted();
    }

    public function sealed(Service $s): string
    {
        return $s->sealed();
    }

    public function bump(Service $s): int
    {
        $n = 1;
        $s->bump($n);

        return $n;
    }

    public function join(Service $s): string
    {
        return $s->join('-', 'a', 'b');
    }

    public function count(Service $s): int
    {
        return $s->count();
    }

    public function sealedTwice(Service $s): string
    {
        return $s->sealed().$s->sealed();
    }

    public function outer(Pipeline $p): string
    {
        return $p->outer();
    }

    public function inner(Pipeline $p): string
    {
        return $p->inner();
    }

    public function whoami(Named $n): string
    {
        return $n->whoami();
    }

    public function widget(): Widget
    {
        return new Widget();
    }

    public function plainWidget(): PlainWidget
    {
        return new PlainWidget();
    }

    public function final(FinalService $s): string
    {
        return $s->hello();
    }

    public function shout(string $text): string
    {
        return shout($text);
    }

    public function repeat(string $text, int $times): string
    {
        return str_repeat($text, $times);
    }
}

#[Aspect]
final class JournalAspect
{
    #[Around('execution(* AopE2E\Service::*(..))')]
    public function service(ProceedingJoinPointInterface $pjp): mixed
    {
        Journal::$entries[] = '>'.$pjp->getSignature()->getName();

        return $pjp->proceed();
    }

    #[Around('execution(* AopE2E\FinalService::*(..))')]
    public function finalClass(ProceedingJoinPointInterface $pjp): mixed
    {
        return Journal::text($pjp->proceed()).' (advised)';
    }

    #[Around('execution(* AopE2E\shout(..))')]
    public function func(ProceedingJoinPointInterface $pjp): mixed
    {
        return $pjp->proceed([Journal::text($pjp->getArgs()[0]).'?']);
    }

    #[Before('execution(* str_repeat(..))')]
    public function internal(): void
    {
        Journal::$entries[] = 'str_repeat';
    }
}

#[Aspect]
final class BumpAspect
{
    #[Around('execution(* AopE2E\Service::bump(..))')]
    public function bump(ProceedingJoinPointInterface $pjp): mixed
    {
        return $pjp->proceed([10]);
    }
}

#[Aspect]
final class WrongTypeAspect
{
    #[Around('execution(* AopE2E\Service::count(..))')]
    public function wrong(ProceedingJoinPointInterface $pjp): mixed
    {
        return 'nope';
    }
}

#[Aspect(scope: AspectScope::PER_TARGET)]
final class PerTargetAspect
{
    private int $calls = 0;

    #[Around('execution(* AopE2E\Service::counted(..)) || execution(* AopE2E\Service::sealed(..))')]
    public function count(ProceedingJoinPointInterface $pjp): mixed
    {
        ++$this->calls;

        return Journal::text($pjp->proceed()).'#'.$this->calls;
    }
}

#[Aspect]
#[DeclareError('execution(* AopE2E\FinalService::hello(..))', 'hello() is forbidden')]
final class ForbidAspect
{
}

final class Pipeline
{
    public function outer(): string
    {
        return 'outer>'.$this->inner();
    }

    public function inner(): string
    {
        return 'inner';
    }
}

final class Named
{
    public function __construct(private readonly string $name)
    {
    }

    public function whoami(): string
    {
        return $this->name;
    }
}

final class Gate
{
    public static bool $open = false;

    public static function open(): bool
    {
        return self::$open;
    }
}

#[Configurable(service: 'cfg')]
final class Widget
{
    public string $configured = 'no';
    public string $seenInConstructor = '';

    public function __construct()
    {
        $this->seenInConstructor = $this->configured;
    }
}

#[Configurable(service: 'plain')]
final class PlainWidget
{
    public string $configured = 'no';
}

#[Aspect]
final class FlowAspect
{
    #[Before('execution(* AopE2E\Pipeline::inner(..)) && cflow(execution(* AopE2E\Pipeline::outer(..)))')]
    public function inFlow(): void
    {
        Journal::$entries[] = 'cflow';
    }
}

#[Aspect]
final class CallAspect
{
    #[Around('call(* AopE2E\Pipeline::inner(..))')]
    public function call(ProceedingJoinPointInterface $pjp): mixed
    {
        Journal::$entries[] = 'call>'.get_debug_type($pjp->getThis());

        return $pjp->proceed();
    }

    #[Before('execution(* AopE2E\Pipeline::inner(..))')]
    public function execution(JoinPointInterface $jp): void
    {
        Journal::$entries[] = 'execution>'.$jp->getKind()->value;
    }
}

#[Aspect]
final class WithincodeAspect
{
    #[Before('call(* AopE2E\Pipeline::inner(..)) && withincode(* AopE2E\Pipeline::outer(..))')]
    public function fromOuter(): void
    {
        Journal::$entries[] = 'withincode';
    }
}

#[Aspect]
final class IfAspect
{
    #[Before('execution(* AopE2E\Pipeline::inner(..)) && if(AopE2E\Gate::open())')]
    public function gated(): void
    {
        Journal::$entries[] = 'gated';
    }
}

final class SpringBefore implements MethodBeforeAdviceInterface
{
    public function before(JoinPointInterface $joinPoint): void
    {
        Journal::$entries[] = 'spring:'.$joinPoint->getSignature()->getName();
    }
}

/** A plain class used as a configured aspect (no attributes). */
final class ConfigAspect
{
    public function log(JoinPointInterface $jp): void
    {
        Journal::$entries[] = 'config:'.$jp->getSignature()->getName();
    }
}

class CtorBase
{
    public function __construct()
    {
    }
}

final class CtorChild extends CtorBase
{
    public function __construct()
    {
        parent::__construct();
    }
}

final class CtorHeir extends CtorBase
{
}

#[Aspect]
final class CtorBaseAspect
{
    #[Before('execution(* AopE2E\CtorBase::__construct(..))')]
    public function base(JoinPointInterface $jp): void
    {
        Journal::$entries[] = 'base:'.get_debug_type($jp->getThis());
    }

    #[Before('execution(AopE2E\CtorBase+::__construct(..))')]
    public function baseAndSubtypes(JoinPointInterface $jp): void
    {
        Journal::$entries[] = 'plus:'.get_debug_type($jp->getThis());
    }
}

final class Factory
{
    /**
     * @param class-string $class
     */
    public static function make(string $class): object
    {
        return new $class();
    }
}
