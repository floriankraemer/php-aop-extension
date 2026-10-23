<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\Exception\PointcutSyntaxException;

/**
 * Matches `Fully\Qualified\Class::method` (or a function's fully qualified name) against regular expressions
 * (Spring's JdkRegexpMethodPointcut).
 *
 * A pattern is a PCRE expression without delimiters that must match the WHOLE name, e.g.
 * `.*::set.*` or `App\\Service\\.*::find.*`. The method is tested under the name of its declaring class and
 * under the name of the runtime class. A method is advised when some pattern matches and no excluded pattern does.
 */
final class RegexpMethodPointcut extends StaticMethodMatcherPointcut
{
    /** @var list<string> */
    private array $patterns = [];

    /** @var list<string> */
    private array $excluded = [];

    /**
     * @param string|list<string> $patterns
     * @param string|list<string> $excludedPatterns
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

    private static function compile(string $pattern): string
    {
        // chr(1) as delimiter, so no character of the user's pattern needs escaping
        $regex = "\x01^(?:".$pattern.")$\x01s";
        set_error_handler(static fn (): bool => true);
        try {
            $valid = false !== preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
        if (!$valid) {
            throw new PointcutSyntaxException(sprintf('Invalid regular expression "%s" in RegexpMethodPointcut: %s.', $pattern, preg_last_error_msg()));
        }

        return $regex;
    }
}
