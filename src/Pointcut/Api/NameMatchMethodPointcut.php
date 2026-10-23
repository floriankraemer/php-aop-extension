<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Matches method (or function) names, with `*` as wildcard anywhere in the name (Spring's NameMatchMethodPointcut).
 * Case sensitive. Functions are matched by their fully qualified and by their short name.
 */
final class NameMatchMethodPointcut extends StaticMethodMatcherPointcut
{
    /** @var list<string> */
    private array $mappedNames = [];

    /** @var list<string> */
    private array $regexes = [];

    /**
     * @param string|list<string> $mappedNames
     */
    public function __construct(string|array $mappedNames = [])
    {
        $this->setMappedNames(...(array) $mappedNames);
    }

    public function setMappedName(string $name): self
    {
        return $this->setMappedNames($name);
    }

    public function setMappedNames(string ...$names): self
    {
        $this->mappedNames = [];
        $this->regexes = [];
        foreach ($names as $name) {
            $this->addMethodName($name);
        }

        return $this;
    }

    public function addMethodName(string $name): self
    {
        $this->mappedNames[] = $name;
        $this->regexes[] = '/^'.str_replace('\*', '.*', preg_quote($name, '/')).'$/s';

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMappedNames(): array
    {
        return $this->mappedNames;
    }

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        $names = [$function->getName()];
        if (!$function instanceof \ReflectionMethod) {
            $names[] = $function->getShortName();
        }
        foreach ($this->regexes as $regex) {
            foreach ($names as $name) {
                if (1 === preg_match($regex, $name)) {
                    return true;
                }
            }
        }

        return false;
    }
}
