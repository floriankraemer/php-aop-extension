<?php

declare(strict_types=1);

namespace AOP\Runtime;

/**
 * Turns the raw argument array of the engine (contract section 3: only the values the caller passed, in order,
 * then extra positional and extra named arguments) into the library shape: the declared parameters in order with
 * defaults filled in, then the variadic/extra tail with its original keys.
 *
 * References are kept, so by-reference parameters still write through to the caller.
 *
 * @internal
 */
final class ArgumentNormalizer
{
    private function __construct()
    {
    }

    /**
     * @param list<\ReflectionParameter> $parameters
     * @param array<int|string, mixed>   $raw
     *
     * @return array<int|string, mixed>
     */
    public static function normalize(array $parameters, array $raw): array
    {
        $out = [];
        $declared = 0;
        $gap = false;
        $names = [];
        foreach ($parameters as $index => $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            ++$declared;
            $name = $parameter->getName();
            $names[$name] = true;
            $key = $gap ? $name : $index;
            if (\array_key_exists($index, $raw)) {
                self::copy($out, $key, $raw, $index);
            } elseif (\array_key_exists($name, $raw)) {
                self::copy($out, $key, $raw, $name);
            } elseif (!$gap && $parameter->isDefaultValueAvailable() && !$parameter->isPassedByReference()) {
                try {
                    $out[$key] = $parameter->getDefaultValue();
                } catch (\Throwable) {
                    $gap = true;
                }
            } else {
                $gap = true;
            }
        }

        foreach ($raw as $key => $_) {
            if (\is_int($key) ? $key >= $declared : !isset($names[$key])) {
                self::copy($out, $key, $raw, $key);
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $out
     * @param array<int|string, mixed> $raw
     */
    private static function copy(array &$out, int|string $to, array &$raw, int|string $from): void
    {
        if (null !== \ReflectionReference::fromArrayElement($raw, $from)) {
            $out[$to] = &$raw[$from];
        } else {
            $out[$to] = $raw[$from];
        }
    }
}
