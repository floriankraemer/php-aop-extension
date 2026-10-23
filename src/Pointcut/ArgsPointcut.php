<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Pattern\ArgumentMatcher;

/**
 * args(int, ..), args(id, *): matches the actual call arguments. The static part prunes by arity and
 * by declared parameter types that can never accept the pattern; the runtime part checks each argument.
 */
final class ArgsPointcut implements PointcutInterface
{
    use ArgumentList;

    /**
     * @var list<ArgumentMatcher|null> matcher per entry, null = no constraint
     */
    private array $matchers = [];

    /**
     * @param array<string, BindingSpec> $bindings binding name => spec of the `BindingCandidateNode`s in $params that are advice parameters
     */
    public function __construct(public readonly ParamListNode $params, array $bindings = [])
    {
        $this->initArguments($params, $bindings, BindingKind::ARGS);
        foreach ($this->entries as $entry) {
            $this->matchers[] = match (true) {
                $entry instanceof TypePatternNode => ArgumentMatcher::fromName($entry->pattern, $entry->nullable),
                $entry instanceof BindingSpec && null !== $entry->typeRestriction => ArgumentMatcher::fromName($entry->typeRestriction),
                default => null,
            };
        }
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        if (!$this->arityFeasible($method, $ctx)) {
            return false;
        }
        if (null === $method) {
            // field-set: the declared property type constrains the value; a handler's argument is the exception
            $matcher = $this->matchers[0] ?? null;

            return null === $matcher || JoinPointKind::FIELD_SET !== $ctx->kind || self::compatible($matcher, $ctx->property?->getType());
        }
        $parameters = $method->getParameters();
        foreach ($this->matchers as $i => $matcher) {
            $parameter = $parameters[$i] ?? (($parameters[count($parameters) - 1] ?? null)?->isVariadic() ? $parameters[count($parameters) - 1] : null);
            if (null !== $matcher && null !== $parameter && !self::compatible($matcher, $parameter->getType())) {
                return false;
            }
        }

        return true;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $args = array_values($ctx->args());
        if (!$this->runtimeArityMatches($args)) {
            return false;
        }
        foreach ($this->matchers as $i => $matcher) {
            if (null !== $matcher && !$matcher->matches($args[$i])) {
                return false;
            }
        }
        // bind only after everything matched
        foreach ($this->entries as $i => $entry) {
            if ($entry instanceof BindingSpec) {
                $ctx->bind($entry->name, $args[$i]);
            }
        }

        return true;
    }

    /**
     * False only when the declared type is known and can never accept an argument matching the pattern.
     */
    private static function compatible(ArgumentMatcher $matcher, ?\ReflectionType $declared): bool
    {
        if (!$declared instanceof \ReflectionNamedType) {
            return true; // untyped, union, intersection
        }
        $name = $declared->getName();
        if (in_array($name, ['mixed', 'object', 'iterable', 'callable', 'self', 'static', 'parent'], true)) {
            return true;
        }
        $declaredIsBuiltin = $declared->isBuiltin();
        $builtin = $matcher->builtin();

        if (null === $builtin) {
            return !$declaredIsBuiltin; // class pattern vs builtin declared type
        }
        if ('mixed' === $builtin) {
            return true;
        }
        if ('object' === $builtin) {
            return !$declaredIsBuiltin;
        }
        if (!$declaredIsBuiltin) {
            return false;
        }
        if ('null' === $builtin) {
            return $declared->allowsNull();
        }

        return $builtin === $name || ('int' === $builtin && 'float' === $name) || ('bool' === $builtin && in_array($name, ['false', 'true'], true));
    }
}
