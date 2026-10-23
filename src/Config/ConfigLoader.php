<?php

declare(strict_types=1);

namespace AOP\Config;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorFactory;
use AOP\Advisor\Api\AdvisorAdapterRegistry;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Advisor\MetadataNamedPointcutResolver;
use AOP\Aspect\AspectMetadata;
use AOP\Aspect\AspectScope;
use AOP\Aspect\IntroductionDefinition;
use AOP\Attribute\Aspect;
use AOP\Binding\ParameterBinder;
use AOP\Exception\AopException;
use AOP\Pointcut\Api\ExpressionPointcut;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;

/**
 * Spring's schema-based style (`<aop:config>`) as a PHP array, for aspects that are plain classes without attributes.
 *
 * ```php
 * $result = ConfigLoader::load([
 *     'pointcuts' => [                                   // <aop:pointcut id expression>, shared by every aspect
 *         'services' => 'execution(* App\Service\**::*(..))',
 *     ],
 *     'aspects' => [                                     // <aop:aspect>
 *         [
 *             'id' => 'audit',                           // optional, used in messages
 *             'class' => App\AuditAspect::class,         // or 'ref' => 'service id' (needs $aspectFactory) or 'instance' => $object
 *             'order' => 10,                             // default PHP_INT_MAX; lower runs first
 *             'scope' => 'singleton',                    // singleton | pertarget | perthis
 *             'pointcuts' => ['writes' => 'execution(* **::save*(..))'],   // aspect-local, may override shared ones
 *             'advice' => [
 *                 ['type' => 'before', 'pointcut-ref' => 'services', 'method' => 'log'],
 *                 ['type' => 'after-returning', 'pointcut' => 'writes()', 'method' => 'saved', 'returning' => 'result'],
 *                 ['type' => 'after-throwing', 'pointcut-ref' => 'services', 'method' => 'failed', 'throwing' => 'e'],
 *                 ['type' => 'around', 'pointcut' => 'execution(* **::find*(..)) && args(id)', 'method' => 'cache', 'arg-names' => ['id']],
 *                 ['type' => 'after', 'pointcut-ref' => 'services', 'method' => 'cleanup'],
 *             ],
 *             'declare-parents' => [                     // <aop:declare-parents>
 *                 ['types-matching' => 'App\Service\*', 'implement-interface' => Auditable::class, 'default-impl' => AuditableImpl::class],
 *             ],
 *         ],
 *     ],
 *     'advisors' => [                                    // <aop:advisor>
 *         ['id' => 'tx', 'advice' => $interceptor, 'pointcut-ref' => 'services', 'order' => 1],
 *         ['advice-ref' => 'txAdvice', 'pointcut' => 'execution(* **::save*(..))'],
 *     ],
 * ], $aspectFactory);
 * ```
 *
 * - `type`: before, after, after-returning, after-throwing, around. `returning` is only valid for after-returning,
 *   `throwing` only for after-throwing. `pointcut-ref` is a name from `pointcuts` (or `Some\Aspect::name`).
 * - `arg-names` is optional: PHP always knows the parameter names, so it is only checked (each name must be a parameter).
 * - `advisors[].advice` is any object the AdvisorAdapterRegistry supports; `advice-ref` asks `$aspectFactory`.
 * - `$aspectFactory` is `callable(string $classOrRef): object`; without it aspects are created with `new` and need a
 *   constructor without required parameters.
 *
 * Aspects go through the same AdviceDefinition / AspectMetadata / AdvisorFactory path as attribute aspects, so
 * argument binding, advice ordering and named pointcuts behave identically. Classes that carry #[Aspect] are rejected:
 * give them to the Weaver directly.
 */
final class ConfigLoader
{
    private const TYPES = [
        'before' => AdviceType::BEFORE,
        'after' => AdviceType::AFTER,
        'after-returning' => AdviceType::AFTER_RETURNING,
        'after-throwing' => AdviceType::AFTER_THROWING,
        'around' => AdviceType::AROUND,
    ];

    private function __construct(
        private readonly ?\Closure $factory,
        private readonly AdvisorAdapterRegistry $adapters,
    ) {
    }

    /**
     * @param array<array-key, mixed>         $config
     * @param (callable(string): object)|null $aspectFactory
     *
     * @throws ConfigurationException with the path of the offending entry
     */
    public static function load(array $config, ?callable $aspectFactory = null, ?AdvisorAdapterRegistry $adapters = null): ConfigResult
    {
        $loader = new self(null === $aspectFactory ? null : $aspectFactory(...), $adapters ?? new AdvisorAdapterRegistry());

        return $loader->build($config);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function build(array $config): ConfigResult
    {
        self::allowKeys($config, ['pointcuts', 'aspects', 'advisors'], 'aop');
        $shared = self::pointcutMap($config['pointcuts'] ?? [], 'aop.pointcuts');

        $metadata = [];
        $instances = [];
        foreach (self::list($config['aspects'] ?? [], 'aop.aspects') as $i => $entry) {
            [$meta, $instance] = $this->aspect(self::map($entry, "aop.aspects[$i]"), $shared, "aop.aspects[$i]");
            if (isset($instances[$meta->aspectClass])) {
                throw new ConfigurationException(sprintf('aop.aspects[%d]: aspect class %s is configured twice; an aspect class can only be configured once because advisors are identified by class and method.', $i, $meta->aspectClass));
            }
            $metadata[] = $meta;
            $instances[$meta->aspectClass] = $instance;
        }

        $resolver = new MetadataNamedPointcutResolver($metadata);
        $sharedResolver = new ArrayNamedPointcutResolver($shared);
        foreach ($shared as $name => $expression) {
            $this->compile($expression, '', $sharedResolver, sprintf('aop.pointcuts.%s', $name));
        }

        $factory = new AdvisorFactory();
        $binder = new ParameterBinder();
        $aspectAdvisors = [];
        foreach ($metadata as $i => $meta) {
            try {
                $built = $factory->fromMetadata($meta, $resolver);
                foreach ($built as $advisor) {
                    $advice = $advisor->advice ?? throw new \LogicException('aspect advisors carry advice');
                    $binder->plan(new \ReflectionMethod($advice->aspectClass, $advice->methodName), $advice, $advisor->pointcut);
                }
            } catch (AopException $e) {
                throw new ConfigurationException(sprintf('aop.aspects[%d] (%s): %s', $i, $meta->aspectClass, $e->getMessage()), 0, $e);
            }
            array_push($aspectAdvisors, ...$built);
        }

        $programmatic = $this->advisors(self::list($config['advisors'] ?? [], 'aop.advisors'), $shared, $sharedResolver, array_map(static fn (Advisor $a): string => $a->id, $aspectAdvisors));

        return new ConfigResult($metadata, $instances, $aspectAdvisors, $programmatic, $resolver);
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $shared
     *
     * @return array{AspectMetadata, object|\Closure(): object}
     */
    private function aspect(array $entry, array $shared, string $path): array
    {
        self::allowKeys($entry, ['id', 'class', 'ref', 'instance', 'order', 'scope', 'pointcuts', 'advice', 'declare-parents'], $path);
        $given = array_values(array_filter(['class', 'ref', 'instance'], static fn (string $k): bool => array_key_exists($k, $entry)));
        if (1 !== count($given)) {
            throw new ConfigurationException(sprintf('%s: give exactly one of "class", "ref" and "instance" (found %s).', $path, [] === $given ? 'none' : implode(', ', $given)));
        }
        $label = isset($entry['id']) && is_string($entry['id']) ? sprintf('%s (%s)', $path, $entry['id']) : $path;

        $scope = AspectScope::SINGLETON;
        if (array_key_exists('scope', $entry)) {
            $scope = (is_string($entry['scope']) ? AspectScope::tryFrom($entry['scope']) : null) ?? throw new ConfigurationException(sprintf('%s: "scope" must be one of %s.', $label, implode(', ', array_map(static fn (AspectScope $s): string => $s->value, AspectScope::cases()))));
        }
        $order = $entry['order'] ?? PHP_INT_MAX;
        if (!is_int($order)) {
            throw new ConfigurationException(sprintf('%s: "order" must be an integer, %s given.', $label, get_debug_type($order)));
        }

        [$class, $instance] = $this->instantiate($entry, $scope, $label);
        if ([] !== (new \ReflectionClass($class))->getAttributes(Aspect::class)) {
            throw new ConfigurationException(sprintf('%s: %s is an attribute aspect (#[Aspect]); register it with the Weaver instead of configuring it here.', $label, $class));
        }

        $named = [...$shared, ...self::pointcutMap($entry['pointcuts'] ?? [], $path.'.pointcuts')];
        $advices = [];
        foreach (self::list($entry['advice'] ?? [], $path.'.advice') as $j => $advice) {
            $advices[] = $this->advice($class, self::map($advice, "$path.advice[$j]"), $named, count($advices), $scope, "$path.advice[$j]");
        }
        $introductions = [];
        foreach (self::list($entry['declare-parents'] ?? [], $path.'.declare-parents') as $j => $declaration) {
            $introductions[] = $this->introduction(self::map($declaration, "$path.declare-parents[$j]"), "$path.declare-parents[$j]");
        }

        return [new AspectMetadata($class, $order, $named, $advices, $introductions, $scope), $instance];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{class-string, object|\Closure(): object}
     */
    private function instantiate(array $entry, AspectScope $scope, string $label): array
    {
        if (isset($entry['instance'])) {
            return is_object($entry['instance'])
                ? [$entry['instance']::class, $entry['instance']]
                : throw new ConfigurationException(sprintf('%s: "instance" must be an object, %s given.', $label, get_debug_type($entry['instance'])));
        }

        if (isset($entry['class'])) {
            $class = is_string($entry['class']) ? ltrim($entry['class'], '\\') : '';
            if (!class_exists($class)) {
                throw new ConfigurationException(sprintf('%s: aspect class "%s" does not exist.', $label, is_string($entry['class']) ? $entry['class'] : get_debug_type($entry['class'])));
            }
            $create = function () use ($class, $label): object {
                if (null !== $this->factory) {
                    return $this->produce($class, $label);
                }
                $constructor = (new \ReflectionClass($class))->getConstructor();
                if (null !== $constructor && $constructor->getNumberOfRequiredParameters() > 0) {
                    throw new ConfigurationException(sprintf('%s: %s needs constructor arguments; pass an $aspectFactory to ConfigLoader::load() or use "instance".', $label, $class));
                }

                return new $class();
            };
            $instance = $create();

            return [$class, AspectScope::SINGLETON === $scope ? $instance : $create(...)];
        }

        $ref = is_string($entry['ref'] ?? null) && '' !== $entry['ref'] ? $entry['ref'] : throw new ConfigurationException(sprintf('%s: "ref" must be a non-empty string.', $label));
        if (null === $this->factory) {
            throw new ConfigurationException(sprintf('%s: "ref" => "%s" needs an $aspectFactory to be resolved.', $label, $ref));
        }
        $object = $this->produce($ref, $label);

        return [$object::class, AspectScope::SINGLETON === $scope ? $object : fn (): object => $this->produce($ref, $label)];
    }

    private function produce(string $ref, string $label): object
    {
        $object = ($this->factory ?? throw new \LogicException('factory checked by caller'))($ref);

        return is_object($object) ? $object : throw new ConfigurationException(sprintf('%s: the aspect factory returned %s for "%s", expected an object.', $label, get_debug_type($object), $ref));
    }

    /**
     * @param class-string          $class
     * @param array<string, mixed>  $entry
     * @param array<string, string> $named
     */
    private function advice(string $class, array $entry, array $named, int $index, AspectScope $scope, string $path): AdviceDefinition
    {
        self::allowKeys($entry, ['type', 'pointcut', 'pointcut-ref', 'method', 'returning', 'throwing', 'arg-names'], $path);

        $typeName = is_string($entry['type'] ?? null) ? strtolower(str_replace('_', '-', $entry['type'])) : '';
        $type = self::TYPES[$typeName] ?? throw new ConfigurationException(sprintf('%s: "type" must be one of %s, %s given.', $path, implode(', ', array_keys(self::TYPES)), isset($entry['type']) ? var_export($entry['type'], true) : 'nothing'));

        $method = is_string($entry['method'] ?? null) ? $entry['method'] : throw new ConfigurationException(sprintf('%s: "method" (the advice method of %s) is required.', $path, $class));
        if (!method_exists($class, $method)) {
            throw new ConfigurationException(sprintf('%s: %s has no method "%s".', $path, $class, $method));
        }
        $reflection = new \ReflectionMethod($class, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new ConfigurationException(sprintf('%s: advice method %s::%s() must be public and non-static.', $path, $class, $method));
        }

        $expression = $this->expression($entry, $named, $path);
        $returning = self::variable($entry, 'returning', $path);
        $throwing = self::variable($entry, 'throwing', $path);
        if (null !== $returning && AdviceType::AFTER_RETURNING !== $type) {
            throw new ConfigurationException(sprintf('%s: "returning" is only valid for after-returning advice, this is %s.', $path, $typeName));
        }
        if (null !== $throwing && AdviceType::AFTER_THROWING !== $type) {
            throw new ConfigurationException(sprintf('%s: "throwing" is only valid for after-throwing advice, this is %s.', $path, $typeName));
        }
        if (array_key_exists('arg-names', $entry)) {
            $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $reflection->getParameters());
            foreach (self::list($entry['arg-names'], $path.'.arg-names') as $name) {
                $name = is_string($name) ? ltrim($name, '$') : '';
                if (!in_array($name, $parameters, true)) {
                    throw new ConfigurationException(sprintf('%s: arg-names mentions "%s", but %s::%s() has the parameters [%s].', $path, $name, $class, $method, implode(', ', $parameters)));
                }
            }
        }

        return new AdviceDefinition($class, $method, $type, $expression, $returning, $throwing, $index, $scope);
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $named
     */
    private function expression(array $entry, array $named, string $path): string
    {
        $hasExpression = null !== ($entry['pointcut'] ?? null);
        $hasRef = null !== ($entry['pointcut-ref'] ?? null);
        if ($hasExpression === $hasRef) {
            throw new ConfigurationException(sprintf('%s: give exactly one of "pointcut" and "pointcut-ref".', $path));
        }
        if ($hasExpression) {
            return is_string($entry['pointcut']) && '' !== trim($entry['pointcut']) ? $entry['pointcut'] : throw new ConfigurationException(sprintf('%s: "pointcut" must be a non-empty expression string.', $path));
        }

        $ref = is_string($entry['pointcut-ref']) ? trim($entry['pointcut-ref']) : '';
        if (1 === preg_match('/^[A-Za-z_]\w*$/', $ref)) {
            if (!isset($named[$ref])) {
                throw new ConfigurationException(sprintf('%s: "pointcut-ref" => "%s" is not a configured pointcut (known: %s).', $path, $ref, [] === $named ? 'none' : implode(', ', array_keys($named))));
            }

            return $ref.'()';
        }
        if (1 === preg_match('/^\\\\?[A-Za-z_][\w\\\\]*::[A-Za-z_]\w*(\(\))?$/', $ref)) {
            return str_ends_with($ref, '()') ? $ref : $ref.'()';
        }

        throw new ConfigurationException(sprintf('%s: "pointcut-ref" must be a pointcut name or Class::name, %s given.', $path, var_export($entry['pointcut-ref'], true)));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function introduction(array $entry, string $path): IntroductionDefinition
    {
        self::allowKeys($entry, ['types-matching', 'implement-interface', 'default-impl'], $path);
        foreach (['types-matching', 'implement-interface', 'default-impl'] as $key) {
            if (!is_string($entry[$key] ?? null) || '' === $entry[$key]) {
                throw new ConfigurationException(sprintf('%s: "%s" is required.', $path, $key));
            }
        }
        /** @var array{'types-matching': string, 'implement-interface': string, 'default-impl': string} $entry */
        $interface = ltrim($entry['implement-interface'], '\\');
        $impl = ltrim($entry['default-impl'], '\\');
        if (!interface_exists($interface)) {
            throw new ConfigurationException(sprintf('%s: "%s" is not an interface.', $path, $interface));
        }
        if (!class_exists($impl)) {
            throw new ConfigurationException(sprintf('%s: default implementation "%s" does not exist.', $path, $impl));
        }
        if (!is_subclass_of($impl, $interface)) {
            throw new ConfigurationException(sprintf('%s: default implementation "%s" does not implement "%s".', $path, $impl, $interface));
        }

        return new IntroductionDefinition($entry['types-matching'], $interface, $impl);
    }

    /**
     * @param list<mixed>           $entries
     * @param array<string, string> $shared
     * @param list<string>          $takenIds
     *
     * @return list<Advisor>
     */
    private function advisors(array $entries, array $shared, ArrayNamedPointcutResolver $resolver, array $takenIds): array
    {
        $advisors = [];
        foreach ($entries as $i => $raw) {
            $path = "aop.advisors[$i]";
            $entry = self::map($raw, $path);
            self::allowKeys($entry, ['id', 'advice', 'advice-ref', 'pointcut', 'pointcut-ref', 'order'], $path);

            if (array_key_exists('advice', $entry) === array_key_exists('advice-ref', $entry)) {
                throw new ConfigurationException(sprintf('%s: give exactly one of "advice" and "advice-ref".', $path));
            }
            if (array_key_exists('advice', $entry)) {
                $advice = is_object($entry['advice']) ? $entry['advice'] : throw new ConfigurationException(sprintf('%s: "advice" must be an advice object, %s given.', $path, get_debug_type($entry['advice'])));
            } else {
                $ref = is_string($entry['advice-ref']) && '' !== $entry['advice-ref'] ? $entry['advice-ref'] : throw new ConfigurationException(sprintf('%s: "advice-ref" must be a non-empty string.', $path));
                $this->factory ?? throw new ConfigurationException(sprintf('%s: "advice-ref" => "%s" needs an $aspectFactory to be resolved.', $path, $ref));
                $advice = $this->produce($ref, $path);
            }

            $order = $entry['order'] ?? PHP_INT_MAX;
            if (!is_int($order)) {
                throw new ConfigurationException(sprintf('%s: "order" must be an integer, %s given.', $path, get_debug_type($order)));
            }
            $id = $entry['id'] ?? null;
            if (null !== $id && (!is_string($id) || '' === $id)) {
                throw new ConfigurationException(sprintf('%s: "id" must be a non-empty string.', $path));
            }
            if (null !== $id && in_array($id, $takenIds, true)) {
                throw new ConfigurationException(sprintf('%s: advisor id "%s" is used twice.', $path, $id));
            }

            $expression = $this->expression($entry, $shared, $path);
            $pointcut = $this->compile($expression, '', $resolver, $path);
            try {
                $advisor = (new DefaultPointcutAdvisor($pointcut, $advice, $order, $id, $this->adapters))->toAdvisor();
            } catch (AopException $e) {
                throw new ConfigurationException(sprintf('%s: %s', $path, $e->getMessage()), 0, $e);
            }
            $takenIds[] = $advisor->id;
            $advisors[] = $advisor;
        }

        return $advisors;
    }

    private function compile(string $expression, string $aspect, MetadataNamedPointcutResolver|ArrayNamedPointcutResolver $resolver, string $path): ExpressionPointcut
    {
        try {
            return new ExpressionPointcut($expression, new PointcutExpressionCompiler(), [], $resolver, $aspect);
        } catch (AopException $e) {
            throw new ConfigurationException(sprintf('%s: invalid pointcut "%s": %s', $path, $expression, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function pointcutMap(mixed $value, string $path): array
    {
        $map = [];
        foreach (self::map($value, $path) as $name => $expression) {
            if (1 !== preg_match('/^[A-Za-z_]\w*$/', (string) $name)) {
                throw new ConfigurationException(sprintf('%s: "%s" is not a valid pointcut name (letters, digits and underscores).', $path, $name));
            }
            if (!is_string($expression) || '' === trim($expression)) {
                throw new ConfigurationException(sprintf('%s.%s: the expression must be a non-empty string.', $path, $name));
            }
            $map[(string) $name] = $expression;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function variable(array $entry, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $entry)) {
            return null;
        }
        $name = is_string($entry[$key]) ? ltrim($entry[$key], '$') : '';
        if (1 !== preg_match('/^[A-Za-z_]\w*$/', $name)) {
            throw new ConfigurationException(sprintf('%s: "%s" must be the name of an advice parameter.', $path, $key));
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string>            $allowed
     */
    private static function allowKeys(array $array, array $allowed, string $path): void
    {
        $unknown = array_diff(array_map('strval', array_keys($array)), $allowed);
        if ([] !== $unknown) {
            throw new ConfigurationException(sprintf('%s: unknown key(s) %s; allowed: %s.', $path, implode(', ', array_map(static fn (string $k): string => '"'.$k.'"', $unknown)), implode(', ', $allowed)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf('%s: expected an array, %s given.', $path, get_debug_type($value)));
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigurationException(sprintf('%s: expected a list, %s given.', $path, is_array($value) ? 'a map' : get_debug_type($value)));
        }

        return $value;
    }
}
