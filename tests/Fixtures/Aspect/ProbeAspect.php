<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;

/**
 * Not registered as an aspect: its methods are wired to interceptors by hand in the interceptor tests.
 */
final class ProbeAspect
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public ?\Throwable $throwFromAdvice = null;

    public string $aroundMode = 'pass';

    public function plain(JoinPointInterface $jp): void
    {
        $this->calls[] = ['plain', $jp->getArgs()];
        if (null !== $this->throwFromAdvice) {
            throw $this->throwFromAdvice;
        }
    }

    public function returningString(string $result): void
    {
        $this->calls[] = ['returningString', $result];
    }

    public function returningAny(mixed $result): void
    {
        $this->calls[] = ['returningAny', $result];
    }

    public function throwingInvalid(\InvalidArgumentException $e): void
    {
        $this->calls[] = ['throwingInvalid', $e];
    }

    public function throwingAny(\Throwable $e): void
    {
        $this->calls[] = ['throwingAny', $e];
    }

    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        $this->calls[] = ['around', $pjp->getArgs()];

        return match ($this->aroundMode) {
            'twice' => [$pjp->proceed(), $pjp->proceed()],
            'skip' => 'skipped',
            'alter' => $pjp->proceed(['Changed']),
            'replace' => strtoupper(is_string($r = $pjp->proceed()) ? $r : ''),
            default => $pjp->proceed(),
        };
    }
}
