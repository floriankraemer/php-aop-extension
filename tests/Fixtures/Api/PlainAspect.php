<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;

/**
 * An aspect without any attribute, wired by configuration only.
 */
class PlainAspect
{
    /** @var list<string> */
    public array $log = [];

    public function before(JoinPointInterface $jp): void
    {
        $this->log[] = 'before:'.$jp->getSignature()->getName();
    }

    public function after(JoinPointInterface $jp): void
    {
        $this->log[] = 'after:'.$jp->getSignature()->getName();
    }

    public function returned(JoinPointInterface $jp, mixed $result): void
    {
        $this->log[] = 'returned:'.var_export($result, true);
    }

    public function failed(JoinPointInterface $jp, \Throwable $error): void
    {
        $this->log[] = 'failed:'.$error->getMessage();
    }

    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        $this->log[] = 'around-in';
        $result = $pjp->proceed();
        $this->log[] = 'around-out';

        return $result;
    }

    public function withArgument(ProceedingJoinPointInterface $pjp, int $amount): mixed
    {
        $this->log[] = 'amount:'.$amount;

        return $pjp->proceed();
    }

    protected function hidden(): void
    {
    }

    public static function statically(): void
    {
    }
}
