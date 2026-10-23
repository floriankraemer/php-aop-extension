<?php

declare(strict_types=1);

namespace AOP\Tests\Support;

use AOP\Runtime\EngineBridgeInterface;

/**
 * Pure-PHP stand-in for the C engine: builds the same join point descriptors and argument arrays
 * (docs/internals/contract.md §2, §3), applies the same decision cache and caller-sensitive protocol (§1.1)
 * and hands the dispatcher a closure that runs the original code through reflection.
 *
 * Unlike the engine it intercepts only what a test routes through call()/callFunction().
 */
final class FakeEngine implements EngineBridgeInterface
{
    private const ADVISE = 1;
    private const TRACK = 2;
    private const CALLER_SENSITIVE = 4;

    /** @var (callable(array<string, mixed>): int)|null */
    private $matcher;

    /** @var (callable(array<string, mixed>, ?object, array<int|string, mixed>, callable): mixed)|null */
    private $dispatcher;

    /** @var array<string, int> */
    private array $decisions = [];

    public int $matcherCalls = 0;

    public int $resets = 0;

    public bool $fieldInterception = true;

    public function register(callable $matcher, callable $dispatcher): void
    {
        $this->matcher = $matcher;
        $this->dispatcher = $dispatcher;
        $this->decisions = [];
    }

    public function unregister(): void
    {
        $this->matcher = null;
        $this->dispatcher = null;
        $this->decisions = [];
    }

    public function reset(): void
    {
        $this->decisions = [];
        ++$this->resets;
    }

    public function fieldInterceptionAvailable(): bool
    {
        return $this->fieldInterception;
    }

    public function isRegistered(): bool
    {
        return null !== $this->dispatcher;
    }

    /**
     * Calls a method as if from $caller (keys class, scope, function, file, line, object; null = internal caller).
     *
     * @param object|class-string       $target an instance, or a class name for static methods
     * @param array<int|string, mixed>  $args   raw arguments as C passes them (contract §3)
     * @param array<string, mixed>|null $caller
     */
    public function call(object|string $target, string $method, array $args = [], ?array $caller = null): mixed
    {
        $class = is_object($target) ? $target::class : $target;
        $reflection = new \ReflectionMethod($class, $method);
        $object = is_object($target) && !$reflection->isStatic() ? $target : null;
        $descriptor = [
            'kind' => '__construct' === strtolower($reflection->getName()) ? 'constructor-execution' : 'method-execution',
            'class' => $class,
            'scope' => $reflection->getDeclaringClass()->getName(),
            'name' => $reflection->getName(),
            'static' => $reflection->isStatic(),
            'internal' => $reflection->isInternal(),
        ];
        $original = static fn (array $a): mixed => $reflection->invokeArgs($object, $a);

        return $this->intercept($descriptor, $object, $args, $caller, $original, $class.'::'.$reflection->getName());
    }

    /**
     * @param array<int|string, mixed>  $args
     * @param array<string, mixed>|null $caller
     */
    public function callFunction(string $function, array $args = [], ?array $caller = null): mixed
    {
        $reflection = new \ReflectionFunction($function);
        $descriptor = [
            'kind' => 'function-execution',
            'class' => null,
            'scope' => null,
            'name' => $reflection->getName(),
            'static' => false,
            'internal' => $reflection->isInternal(),
        ];
        $original = static fn (array $a): mixed => $reflection->invokeArgs($a);

        return $this->intercept($descriptor, null, $args, $caller, $original, $reflection->getName());
    }

    /**
     * @param array<string, mixed>                      $descriptor
     * @param array<int|string, mixed>                  $args
     * @param array<string, mixed>|null                 $caller
     * @param \Closure(array<int|string, mixed>): mixed $original
     */
    private function intercept(array $descriptor, ?object $object, array $args, ?array $caller, \Closure $original, string $key): mixed
    {
        if (null === $this->matcher || null === $this->dispatcher) {
            return $original($args);
        }

        $decision = $this->decide($descriptor + ['caller' => null], $key);
        if (0 !== ($decision & self::CALLER_SENSITIVE)) {
            $staticCaller = null === $caller ? null : array_intersect_key($caller, array_flip(['class', 'scope', 'function', 'file']));
            $decision = $this->decide($descriptor + ['caller' => $staticCaller], $key.'@'.self::str($caller['scope'] ?? null).'::'.self::str($caller['function'] ?? null)) & ~self::CALLER_SENSITIVE;
        }

        if (0 === ($decision & (self::ADVISE | self::TRACK))) {
            return $original($args);
        }

        $full = $descriptor + ['caller' => $caller];
        if ('constructor-execution' === $descriptor['kind']) {
            $full['outermost'] = true;
        }

        return ($this->dispatcher)($full, $object, $args, $original);
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function decide(array $descriptor, string $key): int
    {
        if (isset($this->decisions[$key])) {
            return $this->decisions[$key];
        }
        \assert(null !== $this->matcher);
        ++$this->matcherCalls;

        return $this->decisions[$key] = ($this->matcher)($descriptor);
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
