<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Expression\Ast\BindingCandidateNode;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WildcardParamNode;

/**
 * Shared by args()/@args(): the positional entry list and arity handling.
 *
 * @internal
 */
trait ArgumentList
{
    /**
     * @var list<TypePatternNode|BindingSpec|null> null = `*`
     */
    private array $entries = [];
    private bool $ellipsis = false;

    /**
     * @param array<string, BindingSpec> $bindings
     */
    private function initArguments(ParamListNode $params, array $bindings, BindingKind $kind): void
    {
        $this->ellipsis = $params->trailingEllipsis;
        foreach ($params->params as $param) {
            $this->entries[] = match (true) {
                $param instanceof WildcardParamNode => null,
                $param instanceof BindingCandidateNode => $bindings[$param->name] ?? new BindingSpec($param->name, $kind, null),
                default => $param,
            };
        }
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function isRuntime(): bool
    {
        foreach ($this->entries as $entry) {
            if (null !== $entry) {
                return true;
            }
        }

        return false;
    }

    public function bindings(): array
    {
        $bindings = [];
        foreach ($this->entries as $entry) {
            if ($entry instanceof BindingSpec) {
                $bindings[$entry->name] = $entry;
            }
        }

        return $bindings;
    }

    /**
     * Can a call to $method possibly be made with an argument count this list accepts? Proxies always forward
     * every declared parameter (defaults filled in), so only a variadic tail makes the count vary.
     */
    private function arityFeasible(\ReflectionMethod $method): bool
    {
        $wanted = count($this->entries);
        $declared = $method->getNumberOfParameters();
        if ($method->isVariadic()) {
            return $this->ellipsis || $wanted >= $declared - 1;
        }

        return $this->ellipsis ? $declared >= $wanted : $declared === $wanted;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function runtimeArityMatches(array $args): bool
    {
        return $this->ellipsis ? count($args) >= count($this->entries) : count($args) === count($this->entries);
    }
}
