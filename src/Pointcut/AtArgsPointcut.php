<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Pattern\TypePattern;

/**
 * `@args(Attr, ..)`: every listed argument must be an object whose class carries the attribute.
 * A binding binds the attribute instance; its type restriction names the attribute class.
 */
final class AtArgsPointcut implements PointcutInterface
{
    use ArgumentList;

    /**
     * @param array<string, BindingSpec> $bindings binding name => spec of the `BindingCandidateNode`s in $params that are advice parameters
     */
    public function __construct(public readonly ParamListNode $params, array $bindings = [])
    {
        $this->initArguments($params, $bindings, BindingKind::AT_ARGS);
    }

    public function isRuntime(): bool
    {
        return true; // attribute presence depends on the argument's class
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return $this->arityFeasible($method, $ctx);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $args = array_values($ctx->args());
        if (!$this->runtimeArityMatches($args)) {
            return false;
        }

        $found = [];
        foreach ($this->entries as $i => $entry) {
            if (null === $entry) {
                continue;
            }
            $restriction = $entry instanceof BindingSpec ? $entry->classRestriction() : null;
            $pattern = match (true) {
                $entry instanceof TypePatternNode => new TypePattern($entry->pattern, false, $entry->includeSubtypes),
                null !== $restriction => new TypePattern($restriction, false, true),
                default => null,
            };
            $attribute = is_object($args[$i]) ? self::attribute($args[$i], $pattern) : null;
            if (null === $attribute) {
                return false;
            }
            if ($entry instanceof BindingSpec) {
                $found[$entry->name] = $attribute->newInstance();
            }
        }
        foreach ($found as $name => $value) {
            $ctx->bind($name, $value);
        }

        return true;
    }

    /**
     * @return \ReflectionAttribute<object>|null
     */
    private static function attribute(object $argument, ?TypePattern $pattern): ?\ReflectionAttribute
    {
        foreach ((new \ReflectionObject($argument))->getAttributes() as $attribute) {
            if (null === $pattern || $pattern->matchesName($attribute->getName())) {
                return $attribute;
            }
        }

        return null;
    }
}
