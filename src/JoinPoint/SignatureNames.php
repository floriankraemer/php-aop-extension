<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * @internal helpers shared by the signature classes
 */
final class SignatureNames
{
    private function __construct()
    {
    }

    public static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return false === $position ? $name : substr($name, $position + 1);
    }

    /**
     * @param array<int, \ReflectionParameter> $parameters
     */
    public static function parameterList(array $parameters): string
    {
        return implode(', ', array_map(
            static function (\ReflectionParameter $parameter): string {
                $type = $parameter->getType();

                return ($parameter->isVariadic() ? '...' : '').(null === $type ? 'mixed' : (string) $type);
            },
            $parameters,
        ));
    }
}
