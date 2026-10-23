<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\Pointcut\OutermostAwareInterface;

/**
 * Each proceed() call hands the next interceptor its own copy positioned one step further along the chain,
 * so an interceptor may call proceed() several times.
 */
final class MethodInvocation implements ProceedingJoinPointInterface, OutermostAwareInterface
{
    private int $index = 0;

    /**
     * @var array<string, mixed>
     */
    private array $boundArguments = [];

    /**
     * @param array<int|string, mixed>                  $args
     * @param list<MethodInterceptorInterface>          $interceptors
     * @param \Closure(array<int|string, mixed>): mixed $targetInvoker
     */
    public function __construct(
        private readonly JoinPointData $data,
        private array $args,
        private readonly array $interceptors,
        private readonly \Closure $targetInvoker,
    ) {
    }

    /**
     * Current argument values; by-reference arguments are handed out as values, so changing the returned array
     * never reaches the caller (use `proceed($args)` for that).
     */
    public function getArgs(): array
    {
        return array_map(static fn (mixed $value): mixed => $value, $this->args);
    }

    public function isOutermost(): bool
    {
        return $this->data->outermost;
    }

    public function getKind(): JoinPointKind
    {
        return $this->data->kind;
    }

    public function getThis(): ?object
    {
        return $this->data->thisObject;
    }

    public function getTarget(): ?object
    {
        return $this->data->target;
    }

    public function getSignature(): SignatureInterface
    {
        return $this->data->signature;
    }

    public function getStaticPart(): StaticPart
    {
        return $this->data->staticPart;
    }

    public function getEnclosingStaticPart(): ?StaticPart
    {
        return $this->data->enclosingStaticPart;
    }

    public function getSourceLocation(): ?SourceLocation
    {
        return $this->data->sourceLocation;
    }

    public function toShortString(): string
    {
        return $this->data->signature->toShortString();
    }

    public function toLongString(): string
    {
        return $this->data->staticPart->toLongString();
    }

    /**
     * @param array<string, mixed> $bound values bound by the pointcut, for the next interceptor
     */
    public function withBoundArguments(array $bound): self
    {
        $copy = clone $this;
        $copy->boundArguments = $bound;

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBoundArguments(): array
    {
        return $this->boundArguments;
    }

    /**
     * Replacement values for by-reference parameters reach the caller's variables after the rest of the chain
     * returns (not when it throws): what the target wrote into them, or the replacement itself when it wrote nothing.
     */
    public function proceed(?array $args = null): mixed
    {
        if (null === $args) {
            return $this->next($this->args);
        }

        $cells = [];
        foreach ($this->args as $key => $_) {
            if (array_key_exists($key, $args) && null !== \ReflectionReference::fromArrayElement($this->args, $key)) {
                $cells[$key] = $args[$key];
                $args[$key] = &$cells[$key];
            }
        }
        $result = $this->next($args);
        foreach ($cells as $key => $value) {
            $this->args[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function next(array $args): mixed
    {
        $interceptor = $this->interceptors[$this->index] ?? null;

        if (null === $interceptor) {
            return ($this->targetInvoker)($args);
        }

        $next = clone $this;
        $next->index = $this->index + 1;
        $next->args = $args;

        return $interceptor->invoke($next);
    }
}
