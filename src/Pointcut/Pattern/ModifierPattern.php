<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

/**
 * The modifiers of a method pattern: any combination of `public`, `protected`, `private`, `static`, `final` and
 * `abstract`, each optionally negated with `!` (`private static`, `!static`, `public !final`). All of them must hold.
 *
 * A plain function counts as non-static, public, non-final and non-abstract.
 */
final class ModifierPattern
{
    public const MODIFIERS = ['public', 'protected', 'private', 'static', 'final', 'abstract'];

    /** @var list<array{string, bool}> modifier name and whether it is negated */
    private readonly array $clauses;

    /**
     * @param string|list<string>|null $modifiers `public`, `!static`, ... ; null or an empty list = any
     *
     * @throws \InvalidArgumentException for an unknown modifier
     */
    public function __construct(string|array|null $modifiers = null)
    {
        $clauses = [];
        foreach (null === $modifiers ? [] : (array) $modifiers as $modifier) {
            $negated = str_starts_with($modifier, '!');
            $name = $negated ? substr($modifier, 1) : $modifier;
            if (!in_array($name, self::MODIFIERS, true)) {
                throw new \InvalidArgumentException(self::unknownMessage($name));
            }
            $clauses[] = [$name, $negated];
        }
        $this->clauses = $clauses;
    }

    public static function unknownMessage(string $modifier): string
    {
        return sprintf('Unknown modifier "%s": expected public, protected, private, static, final or abstract, each optionally negated with "!".', $modifier);
    }

    public function matches(\ReflectionFunctionAbstract $function): bool
    {
        if ([] === $this->clauses) {
            return true;
        }
        $method = $function instanceof \ReflectionMethod ? $function : null;
        foreach ($this->clauses as [$name, $negated]) {
            $holds = match ($name) {
                'public' => null === $method || $method->isPublic(),
                'protected' => null !== $method && $method->isProtected(),
                'private' => null !== $method && $method->isPrivate(),
                'static' => null !== $method && $method->isStatic(),
                'final' => null !== $method && $method->isFinal(),
                default => null !== $method && $method->isAbstract(),
            };
            if ($holds === $negated) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does the pattern accept a constructor that is not declared (public, non-static, non-final, non-abstract)?
     */
    public function allowsImplicitConstructor(): bool
    {
        foreach ($this->clauses as [$name, $negated]) {
            if (('public' === $name) === $negated) {
                return false;
            }
        }

        return true;
    }
}
