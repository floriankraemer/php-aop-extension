<?php

declare(strict_types=1);

namespace AOP\Binding;

use AOP\Exception\BindingException;
use AOP\JoinPoint\JoinPointInterface;

/**
 * How to build the argument list of one advice method; resolved once at weave time.
 */
final readonly class BindingPlan
{
    /**
     * @param list<array{0: 'jp'|'static_part'|'returning'|'throwing'|'bound', 1?: string}> $slots
     */
    public function __construct(
        private string $adviceId,
        private array $slots,
        private ?\ReflectionType $returningType,
        private ?\ReflectionType $throwingType,
    ) {
    }

    /**
     * @param array<string, mixed> $bound values collected by the pointcut's runtime evaluation
     */
    public function resolve(JoinPointInterface $joinPoint, array $bound, mixed $returnValue = null, ?\Throwable $thrown = null): BoundArguments
    {
        $values = [];
        foreach ($this->slots as $slot) {
            $values[] = match ($slot[0]) {
                'jp' => $joinPoint,
                'static_part' => $joinPoint->getStaticPart(),
                'returning' => $returnValue,
                'throwing' => $thrown,
                'bound' => array_key_exists($slot[1] ?? '', $bound)
                    ? $bound[$slot[1] ?? '']
                    : throw new BindingException(sprintf('Binding $%s was not produced by the pointcut of %s.', $slot[1] ?? '', $this->adviceId)),
            };
        }

        return new BoundArguments($values);
    }

    public function acceptsReturnValue(mixed $value): bool
    {
        return self::accepts($this->returningType, $value);
    }

    public function acceptsThrowable(\Throwable $throwable): bool
    {
        return self::accepts($this->throwingType, $throwable);
    }

    private static function accepts(?\ReflectionType $type, mixed $value): bool
    {
        if (null === $type) {
            return true;
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::accepts($member, $value)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::accepts($member, $value)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return true;
        }
        if (null === $value && $type->allowsNull()) {
            return true;
        }

        $name = $type->getName();
        if (!$type->isBuiltin()) {
            return $value instanceof $name;
        }

        return match ($name) {
            'mixed' => true,
            'object' => is_object($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'false', 'true' => $value === ('true' === $name),
            default => get_debug_type($value) === $name,
        };
    }
}
