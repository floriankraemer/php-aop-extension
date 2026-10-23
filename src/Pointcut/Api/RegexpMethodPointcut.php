<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Matches `Fully\Qualified\Class::method` (or a function's fully qualified name) against regular expressions
 * (Spring's JdkRegexpMethodPointcut).
 *
 * A pattern is either
 *
 * - a bare PCRE expression without delimiters that must match the WHOLE name (it is anchored for you), e.g.
 *   `.*::set.*` or `App\\Service\\.*::find.*`, or
 * - a fully delimited PCRE with optional flags that is used as written, so it matches anywhere in the name unless you
 *   anchor it: `/Catalog::save/i`, `#^App\\.*::get#`. It is detected when the pattern starts with a delimiter (not
 *   alphanumeric, not a backslash, not whitespace) and ends with the closing delimiter and modifier letters only.
 *
 * The method is tested under the name of its declaring class and under the name of the runtime class. A method is
 * advised when some pattern matches and no excluded pattern does.
 *
 * @throws \InvalidArgumentException from the constructor and the setters for a pattern that does not compile
 */
final class RegexpMethodPointcut extends StaticMethodMatcherPointcut
{
    /** Characters that start a bare expression as often as a delimited one: `(a|b)::m`, `[A-Z].*::m`, `.*::m.` */
    private const BARE_STARTS = '([{<.^*+?|$';

    /** @var list<string> */
    private array $patterns = [];

    /** @var list<string> */
    private array $excluded = [];

    /**
     * @param string|list<string> $patterns
     * @param string|list<string> $excludedPatterns
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $patterns = [], string|array $excludedPatterns = [])
    {
        $this->setPatterns((array) $patterns);
        $this->setExcludedPatterns((array) $excludedPatterns);
    }

    public function setPattern(string $pattern): self
    {
        return $this->setPatterns([$pattern]);
    }

    /**
     * @param list<string> $patterns
     */
    public function setPatterns(array $patterns): self
    {
        $this->patterns = array_map(self::compile(...), $patterns);
        $this->patternSources = $patterns;

        return $this;
    }

    public function setExcludedPattern(string $pattern): self
    {
        return $this->setExcludedPatterns([$pattern]);
    }

    /**
     * @param list<string> $patterns
     */
    public function setExcludedPatterns(array $patterns): self
    {
        $this->excluded = array_map(self::compile(...), $patterns);
        $this->excludedSources = $patterns;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getPatterns(): array
    {
        return $this->patternSources;
    }

    /**
     * @return list<string>
     */
    public function getExcludedPatterns(): array
    {
        return $this->excludedSources;
    }

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        foreach (self::names($function, $class) as $name) {
            if ($this->matchesAny($this->patterns, $name) && !$this->matchesAny($this->excluded, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @var list<string> */
    private array $patternSources = [];

    /** @var list<string> */
    private array $excludedSources = [];

    /**
     * @param list<string> $compiled
     */
    private function matchesAny(array $compiled, string $name): bool
    {
        foreach ($compiled as $regex) {
            if (1 === preg_match($regex, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object>|null $class
     *
     * @return list<string>
     */
    private static function names(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): array
    {
        if (!$function instanceof \ReflectionMethod) {
            return [$function->getName()];
        }
        $names = [$function->getDeclaringClass()->getName().'::'.$function->getName()];
        if (null !== $class && $class->getName() !== $function->getDeclaringClass()->getName()) {
            $names[] = $class->getName().'::'.$function->getName();
        }

        return $names;
    }

    /**
     * A fully delimited PCRE (`/Catalog::save/i`, `#...#`, `{...}`) is used as written. Anything else is a bare pattern that
     * must match the whole name; it gets anchored and delimited.
     *
     * @throws \InvalidArgumentException when the pattern does not compile
     */
    private static function compile(string $pattern): string
    {
        $delimited = self::delimited($pattern);
        if (null !== $delimited && self::compiles($delimited)) {
            return $delimited;
        }
        // chr(1) as delimiter, so no character of the user's pattern needs escaping
        $bare = "\x01^(?:".$pattern.")$\x01s";
        // `(a|b)::x(i)` or `[A-Z].*::foo[a-z]` look delimited but are bare expressions. A pattern that starts with an
        // ordinary delimiter (`/`, `#`, ...) and does not compile is an error, not a bare pattern.
        if ((null === $delimited || str_contains(self::BARE_STARTS, $pattern[0])) && self::compiles($bare)) {
            return $bare;
        }

        throw new \InvalidArgumentException(sprintf('Invalid regular expression "%s" in RegexpMethodPointcut: %s.', $pattern, self::lastError(null === $delimited ? $bare : $delimited)));
    }

    /**
     * The pattern when it looks like `<delimiter>body<closing delimiter>flags`: it starts with a character that is neither
     * alphanumeric, a backslash nor whitespace, and ends with the closing delimiter followed by modifier letters only.
     */
    private static function delimited(string $pattern): ?string
    {
        if (strlen($pattern) < 2) {
            return null;
        }
        $open = $pattern[0];
        if (ctype_alnum($open) || '\\' === $open || ctype_space($open) || "\0" === $open) {
            return null;
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($pattern, $close);
        if (false === $end || $end < 1 || 1 !== preg_match('/^[imsxADSUXJun]*$/', substr($pattern, $end + 1))) {
            return null;
        }

        return $pattern;
    }

    private static function compiles(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return false !== preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
    }

    private static function lastError(string $regex): string
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            preg_match($regex, '');

            return preg_last_error_msg();
        } catch (\ErrorException $e) {
            return preg_replace('/^preg_match\(\): /', '', $e->getMessage()) ?? $e->getMessage();
        } finally {
            restore_error_handler();
        }
    }
}
