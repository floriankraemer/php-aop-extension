<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorChainFactory;
use AOP\Advisor\AdvisorRegistry;
use AOP\Attribute\Aspect;
use AOP\Binding\ParameterBinder;
use AOP\Exception\AdviceReturnException;
use AOP\Exception\DeclareErrorException;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\AdviceSignature;
use AOP\JoinPoint\CatchClauseSignature;
use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\SignatureInterface;
use AOP\JoinPoint\SourceLocation;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\ControlFlowInterface;
use AOP\Pointcut\ControlFlowPointcuts;
use AOP\Pointcut\EnclosingContext;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\StaticMatchContext;

/**
 * The matcher and the dispatcher the engine calls (docs/internals/contract.md sections 1 and 2).
 *
 * The matcher decides per join point shape whether it is dispatched; the dispatcher builds (and caches) the chain
 * `[call-kind interceptors] -> [execution-kind interceptors] -> original` and runs it.
 */
final class NativeRuntime
{
    public const ADVISE = 1;
    public const TRACK = 2;
    public const CALLER_SENSITIVE = 4;

    private RuntimeSettings $settings;
    private readonly ControlFlowStack $flow;
    private readonly ScopedAspectResolver $aspects;
    private AdvisorChainFactory $chains;

    /** @var list<Advisor> sorted, outermost first */
    private array $advisors = [];

    /** @var list<PointcutInterface> pointcuts whose join points must be on the control flow stack */
    private array $trackers = [];

    private bool $anyCallerSensitive = false;

    /** @var array<string, JoinPointPlan> */
    private array $plans = [];

    /** @var array<string, list<MethodInterceptorInterface>|null> */
    private array $advicePlans = [];

    /** @var array<string, true> */
    private array $reported = [];

    /** @var array<string, string|null> */
    private array $classServiceCache = [];

    /** @var \WeakMap<object, string> */
    private \WeakMap $instanceServices;

    /** @var array<class-string, array<string, true>> */
    private array $instanceServiceNames = [];

    /** @var \WeakMap<object, AdvisedInstance> */
    private \WeakMap $advised;

    /** @var array<class-string, true> */
    private array $advisedClasses = [];

    public function __construct()
    {
        $this->settings = new RuntimeSettings();
        $this->flow = new ControlFlowStack();
        $this->aspects = new ScopedAspectResolver($this->flow);
        $this->aspects->wrapWith($this->wrapAdvice(...));
        $this->chains = $this->newChains();
        $this->instanceServices = new \WeakMap();
        $this->advised = new \WeakMap();
    }

    public function aspects(): ScopedAspectResolver
    {
        return $this->aspects;
    }

    public function controlFlow(): ControlFlowInterface
    {
        return $this->flow;
    }

    public function flowStack(): ControlFlowStack
    {
        return $this->flow;
    }

    /**
     * @return list<Advisor> sorted, outermost first
     */
    public function advisors(): array
    {
        return $this->advisors;
    }

    public function configure(RuntimeSettings $settings): void
    {
        $this->settings = $settings;
        $this->advisors = AdvisorOrdering::sort($settings->advisors, $settings->precedence);
        $this->chains = $this->newChains();
        $this->trackers = [];
        $this->anyCallerSensitive = false;
        $all = array_map(static fn (Advisor $a): PointcutInterface => $a->pointcut, $this->advisors);
        foreach ($settings->declareRules as $rule) {
            $all[] = $rule->pointcut;
        }
        foreach ($settings->flowEntries as $entry) {
            $this->trackers[] = $entry->pointcut;
            $all[] = $entry->pointcut;
        }
        foreach ($this->advisors as $advisor) {
            foreach (ControlFlowPointcuts::of($advisor->pointcut) as $inner) {
                $this->trackers[] = $inner;
            }
        }
        foreach ($all as $pointcut) {
            $this->anyCallerSensitive = $this->anyCallerSensitive || $pointcut->isCallerSensitive();
        }
        $this->clearCaches();
    }

    public function registerInstanceService(object $instance, string $service): void
    {
        $this->instanceServices[$instance] = $service;
        $this->instanceServiceNames[$instance::class][$service] = true;
        $this->clearCaches();
    }

    public function advised(object $instance, \Closure $changed, \Closure $weaverFrozen): AdvisedInstance
    {
        return $this->advised[$instance] ??= new AdvisedInstance($instance, function (object $object) use ($changed): void {
            $this->advisedClasses[$object::class] = true;
            $changed();
        }, $weaverFrozen);
    }

    // ---------------------------------------------------------------- matcher

    /**
     * @param array<string, mixed> $descriptor
     */
    public function match(array $descriptor): int
    {
        try {
            return $this->decide($descriptor);
        } catch (\ReflectionException) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $d
     */
    private function decide(array $d): int
    {
        $kind = JoinPointKind::tryFrom(self::str($d['kind'] ?? null) ?? '');
        if (null === $kind) {
            return 0;
        }
        $shadow = $this->shadow($d, $kind);
        if (null === $shadow) {
            return 0;
        }

        $callerArray = self::callerOf($d);
        $caller = $this->enclosing($callerArray);
        $advise = null !== $shadow['class'] && isset($this->advisedClasses[$shadow['class']->getName()]);
        $track = false;
        $sensitive = false;
        $probe = '*' === ($d['name'] ?? null) && (JoinPointKind::FIELD_GET === $kind || JoinPointKind::FIELD_SET === $kind);

        foreach ($this->kindsFor($kind, true) as $k) {
            $isCallSite = $k->isCall() || !self::hasCallVariant($kind);
            foreach ($this->serviceCandidates($shadow['class']) as $service) {
                $ctx = $this->context($shadow, $k, $k->isCall() ? $caller : null, $service);
                foreach ($this->advisors as $advisor) {
                    if (!$advise && $this->applies($advisor->pointcut, $ctx, $probe)) {
                        $advise = true;
                    }
                }
                foreach ($this->settings->declareRules as $rule) {
                    if (!$advise && $this->applies($rule->pointcut, $ctx, $probe)) {
                        $advise = true;
                    }
                }
                if (!$advise && !$track) {
                    foreach ($this->trackers as $tracker) {
                        if ($this->applies($tracker, $ctx, $probe)) {
                            $track = true;
                            break;
                        }
                    }
                }
                if (null === $callerArray && $isCallSite && $this->anyCallerSensitive && !$sensitive) {
                    $sensitive = $this->anySensitive($ctx);
                }
            }
        }

        return ($advise ? self::ADVISE : ($track ? self::TRACK : 0)) | ($sensitive ? self::CALLER_SENSITIVE : 0);
    }

    private function anySensitive(StaticMatchContext $ctx): bool
    {
        $pointcuts = array_map(static fn (Advisor $a): PointcutInterface => $a->pointcut, $this->advisors);
        foreach ($this->settings->declareRules as $rule) {
            $pointcuts[] = $rule->pointcut;
        }
        foreach ($this->settings->flowEntries as $entry) {
            $pointcuts[] = $entry->pointcut;
        }
        foreach ($pointcuts as $pointcut) {
            if ($pointcut->isCallerSensitive() && $pointcut->matchesClass($ctx->targetClass, $ctx)) {
                return true;
            }
        }

        return false;
    }

    private function applies(PointcutInterface $pointcut, StaticMatchContext $ctx, bool $classOnly = false): bool
    {
        if (!$pointcut->matchesClass($ctx->targetClass, $ctx)) {
            return false;
        }

        return $classOnly || $pointcut->matchesMethod($ctx->function, $ctx);
    }

    /**
     * @return list<JoinPointKind> the execution kind and, where the call site matters, the derived call kind
     */
    private function kindsFor(JoinPointKind $kind, bool $withCall): array
    {
        $call = $withCall ? self::callKind($kind) : null;

        return null === $call ? [$kind] : [$kind, $call];
    }

    private static function callKind(JoinPointKind $kind): ?JoinPointKind
    {
        return match ($kind) {
            JoinPointKind::METHOD_EXECUTION => JoinPointKind::METHOD_CALL,
            JoinPointKind::CONSTRUCTOR_EXECUTION => JoinPointKind::CONSTRUCTOR_CALL,
            JoinPointKind::FUNCTION_EXECUTION => JoinPointKind::FUNCTION_CALL,
            default => null,
        };
    }

    private static function hasCallVariant(JoinPointKind $kind): bool
    {
        return null !== self::callKind($kind);
    }

    /**
     * Reflection for a descriptor, or null when the join point must not be advised.
     *
     * @param array<string, mixed> $d
     *
     * @return array{class: ?\ReflectionClass<object>, scope: ?\ReflectionClass<object>, function: ?\ReflectionFunctionAbstract, property: ?\ReflectionProperty, caught: ?\ReflectionClass<object>}|null
     */
    private function shadow(array $d, JoinPointKind $kind): ?array
    {
        $class = self::str($d['class'] ?? null);
        $scope = self::str($d['scope'] ?? null) ?? $class;
        $name = self::str($d['name'] ?? null) ?? '';
        $rc = null;
        $scopeClass = null;
        $function = null;
        $property = null;
        $caught = null;

        if (null !== $class) {
            if (self::reserved($class) || (null !== $scope && self::reserved($scope))) {
                return null;
            }
            /** @var class-string $class */
            $rc = new \ReflectionClass($class);
            /** @var class-string $scope */
            $scopeClass = $scope === $class ? $rc : new \ReflectionClass($scope);
            foreach ([$rc, $scopeClass] as $type) {
                if ($type->isEnum() || $type->isAnonymous() || [] !== $type->getAttributes(Aspect::class)) {
                    return null;
                }
            }
        }

        switch ($kind) {
            case JoinPointKind::METHOD_EXECUTION:
            case JoinPointKind::CONSTRUCTOR_EXECUTION:
                if (null === $scopeClass) {
                    return null;
                }
                $function = $scopeClass->getMethod($name);
                if ($function->isAbstract()) {
                    return null;
                }
                break;
            case JoinPointKind::FUNCTION_EXECUTION:
                if (self::reserved($name)) {
                    return null;
                }
                $function = new \ReflectionFunction($name);
                break;
            case JoinPointKind::FIELD_GET:
            case JoinPointKind::FIELD_SET:
                if (null !== $scopeClass && '*' !== $name && $scopeClass->hasProperty($name)) {
                    $property = $scopeClass->getProperty($name);
                }
                break;
            case JoinPointKind::EXCEPTION_HANDLER:
                if (class_exists($name) || interface_exists($name)) {
                    $caught = new \ReflectionClass($name);
                }
                break;
            default:
                break;
        }

        if (!$this->included($scopeClass, $function)) {
            return null;
        }

        return ['class' => $rc, 'scope' => $scopeClass, 'function' => $function, 'property' => $property, 'caught' => $caught];
    }

    /**
     * Classes and functions in the AOP namespace are the runtime itself and never advised (the test suite's own
     * AOP\Tests namespace is exempt so FakeEngine-based tests can use fixtures).
     */
    private static function reserved(string $name): bool
    {
        $name = ltrim($name, '\\');

        return str_starts_with($name, 'AOP\\') && !str_starts_with($name, 'AOP\\Tests\\');
    }

    /**
     * @param \ReflectionClass<object>|null $scope
     */
    private function included(?\ReflectionClass $scope, ?\ReflectionFunctionAbstract $function): bool
    {
        if ([] === $this->settings->includes && [] === $this->settings->excludes) {
            return true;
        }

        return ([] === $this->settings->includes || self::anyMatches($this->settings->includes, $scope, $function)) && !self::anyMatches($this->settings->excludes, $scope, $function);
    }

    /**
     * @param list<\AOP\Pointcut\Pattern\TypePattern> $patterns
     * @param \ReflectionClass<object>|null           $scope
     */
    private static function anyMatches(array $patterns, ?\ReflectionClass $scope, ?\ReflectionFunctionAbstract $function): bool
    {
        foreach ($patterns as $pattern) {
            if (null !== $scope ? $pattern->matchesType($scope) : (null !== $function && $pattern->matchesName($function->getName()))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{class: ?\ReflectionClass<object>, scope: ?\ReflectionClass<object>, function: ?\ReflectionFunctionAbstract, property: ?\ReflectionProperty, caught: ?\ReflectionClass<object>} $shadow
     */
    private function context(array $shadow, JoinPointKind $kind, ?EnclosingContext $caller, ?string $service): StaticMatchContext
    {
        $introduced = [];
        if (null !== $shadow['class']) {
            foreach ($this->settings->introductions as $introduction) {
                if ($introduction->appliesTo($shadow['class'])) {
                    $introduced[] = $introduction->interface;
                }
            }
        }

        return new StaticMatchContext($kind, $shadow['class'], $shadow['function'], $shadow['property'], $shadow['caught'], $caller, $service, $introduced);
    }

    /**
     * @param \ReflectionClass<object>|null $class
     *
     * @return list<string|null>
     */
    private function serviceCandidates(?\ReflectionClass $class): array
    {
        if (null === $class) {
            return [null];
        }
        $names = [$this->classService($class->getName())];
        foreach (array_keys($this->instanceServiceNames[$class->getName()] ?? []) as $name) {
            $names[] = $name;
        }

        return array_values(array_unique($names, SORT_REGULAR));
    }

    private function classService(string $class): ?string
    {
        if (\array_key_exists($class, $this->classServiceCache)) {
            return $this->classServiceCache[$class];
        }
        $found = null;
        for ($c = $class; false !== $c; $c = get_parent_class($c)) {
            if (isset($this->settings->classServices[$c])) {
                $found = $this->settings->classServices[$c];
                break;
            }
        }

        return $this->classServiceCache[$class] = $found;
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function enclosing(?array $caller): ?EnclosingContext
    {
        if (null === $caller) {
            return null;
        }
        $class = self::str($caller['class'] ?? null);
        $scope = self::str($caller['scope'] ?? null);
        $function = self::str($caller['function'] ?? null);
        $rc = null;
        $rf = null;

        try {
            if (null !== $class && (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false))) {
                $rc = new \ReflectionClass($class);
            }
            if (null !== $function) {
                $rf = null !== $scope ? new \ReflectionMethod($scope, $function) : new \ReflectionFunction($function);
            }
        } catch (\ReflectionException) {
            // closures and other unreflectable code: the function stays unknown
        }

        return new EnclosingContext($rc, $rf, self::str($caller['file'] ?? null));
    }

    // ------------------------------------------------------------- dispatcher

    /**
     * @param array<string, mixed>     $d
     * @param array<int|string, mixed> $args
     */
    public function dispatch(array $d, ?object $thisObject, array $args, callable $original): mixed
    {
        $kind = JoinPointKind::from(self::str($d['kind'] ?? null) ?? '');
        $callerArray = self::callerOf($d);
        $plan = $this->planFor($d, $kind, $thisObject, $callerArray);
        $invoke = static fn (array $a): mixed => $original($a);
        if (null === $plan || $plan->isEmpty()) {
            return $invoke($args);
        }

        if (JoinPointKind::EXCEPTION_HANDLER === $kind) {
            $args = [$d['exception'] ?? null];
        } elseif (JoinPointKind::FIELD_SET === $kind) {
            $args = [$d['value'] ?? ($args[0] ?? null)];
        } elseif ($plan->normalizes) {
            $args = ArgumentNormalizer::normalize($plan->parameters, $args);
        }

        $callerObject = \is_object($callerArray['object'] ?? null) ? $callerArray['object'] : null;
        $isCode = $kind->isExecution() || JoinPointKind::INITIALIZATION === $kind;
        $executing = $isCode || JoinPointKind::EXCEPTION_HANDLER === $kind ? $thisObject : $callerObject;
        $static = (bool) ($d['static'] ?? false);
        $outermost = (bool) ($d['outermost'] ?? true);
        $target = JoinPointKind::EXCEPTION_HANDLER === $kind || $static ? null : $thisObject;
        /** @var class-string|null $calledClass */
        $calledClass = self::str($d['class'] ?? null);

        $runExecution = function (array $a) use ($plan, $executing, $target, $thisObject, $invoke, $calledClass, $outermost): mixed {
            if ([] === $plan->executionChain) {
                return $invoke($a);
            }
            $data = new JoinPointData($plan->executionKind, $executing, $target, $plan->signature, $plan->executionPart, $plan->enclosing, $plan->executionLocation, $outermost);
            $result = (new MethodInvocation($data, $a, $plan->executionChain, $invoke))->proceed();

            return $this->checked($plan, $plan->executionArounds, $result, $thisObject, $calledClass);
        };

        if (null === $plan->callKind || [] === $plan->callChain || null === $callerArray) {
            return $runExecution($args);
        }

        $location = new SourceLocation(self::str($callerArray['file'] ?? null), \is_int($callerArray['line'] ?? null) ? $callerArray['line'] : null, self::str($callerArray['scope'] ?? null), self::str($callerArray['function'] ?? null));
        $data = new JoinPointData($plan->callKind, $callerObject, $target, $plan->signature, $plan->callPart ?? $plan->executionPart, $plan->enclosing, $location);
        $result = (new MethodInvocation($data, $args, $plan->callChain, $runExecution))->proceed();

        return $this->checked($plan, $plan->callArounds, $result, $thisObject, $calledClass);
    }

    /**
     * Only Around advice can replace a result; the original is type-checked by PHP itself.
     *
     * @param list<string>      $arounds
     * @param class-string|null $calledClass
     */
    private function checked(JoinPointPlan $plan, array $arounds, mixed $result, ?object $thisObject, ?string $calledClass): mixed
    {
        if ([] === $arounds || null === $plan->declaringClass) {
            return $result;
        }
        if (!ReturnTypeCheck::accepts($plan->returnType, $result, $thisObject, $plan->declaringClass, $calledClass)) {
            throw new AdviceReturnException(sprintf('Around advice %s returned %s, but %s is declared to return %s.', implode(' or ', $arounds), get_debug_type($result), $plan->signature->toShortString(), (string) $plan->returnType));
        }

        return $result;
    }

    /**
     * @param array<string, mixed>      $d
     * @param array<string, mixed>|null $callerArray
     */
    private function planFor(array $d, JoinPointKind $kind, ?object $thisObject, ?array $callerArray): ?JoinPointPlan
    {
        $key = implode('|', [
            self::str($d['kind'] ?? null), self::str($d['class'] ?? null), self::str($d['scope'] ?? null), self::str($d['name'] ?? null),
            null !== $thisObject ? ($this->instanceServices[$thisObject] ?? '') : '',
            null === $callerArray ? '-' : self::str($callerArray['class'] ?? null).'/'.self::str($callerArray['scope'] ?? null).'/'.self::str($callerArray['function'] ?? null),
            (bool) ($d['outermost'] ?? true) ? 'o' : 'i',
        ]);
        $state = null !== $thisObject ? ($this->advised[$thisObject] ?? null) : null;
        $extras = null === $state ? [] : $state->advisors();
        $cached = null === $state || [] === $extras ? ($this->plans[$key] ?? null) : $state->plan($key);
        if (null !== $cached) {
            return $cached;
        }

        $shadow = $this->shadow($d, $kind);
        if (null === $shadow) {
            return null;
        }
        $plan = $this->buildPlan($d, $kind, $shadow, $thisObject, $callerArray, [] === $extras ? $this->advisors : AdvisorOrdering::sort([...$this->settings->advisors, ...$extras], $this->settings->precedence));
        if (null === $state || [] === $extras) {
            $this->plans[$key] = $plan;
        } else {
            $state->storePlan($key, $plan);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                 $d
     * @param array{class: ?\ReflectionClass<object>, scope: ?\ReflectionClass<object>, function: ?\ReflectionFunctionAbstract, property: ?\ReflectionProperty, caught: ?\ReflectionClass<object>} $shadow
     * @param array<string, mixed>|null                                                                                                                                                            $callerArray
     * @param list<Advisor>                                                                                                                                                                        $advisors
     */
    private function buildPlan(array $d, JoinPointKind $kind, array $shadow, ?object $thisObject, ?array $callerArray, array $advisors): JoinPointPlan
    {
        $function = $shadow['function'];
        $rc = $shadow['class'];
        $service = null !== $thisObject ? ($this->instanceServices[$thisObject] ?? null) : null;
        $service ??= null !== $rc ? $this->classService($rc->getName()) : null;
        $callerContext = $this->enclosing($callerArray);
        $callKind = self::callKind($kind);
        if (JoinPointKind::CONSTRUCTOR_EXECUTION === $kind && !(bool) ($d['outermost'] ?? true)) {
            $callKind = null;
        }
        if (null === $callerArray) {
            $callKind = null;
        }

        $signature = self::signature($d, $kind, $shadow);
        $enclosing = $this->enclosingPart($callerContext);
        $executionPart = new StaticPart($kind, $signature, self::executionLocation($function, $shadow));
        $callPart = null === $callKind ? null : new StaticPart($callKind, $signature, new SourceLocation($callerContext?->file, null, self::str($callerArray['scope'] ?? null), self::str($callerArray['function'] ?? null)));

        $executionCtx = $this->context($shadow, $kind, null, $service);
        $callCtx = null === $callKind ? null : $this->context($shadow, $callKind, $callerContext, $service);
        // Field accesses and handlers are caller-relative themselves.
        if (null === $callKind && null !== $callerContext && !$kind->isExecution() && JoinPointKind::INITIALIZATION !== $kind) {
            $executionCtx = $this->context($shadow, $kind, $callerContext, $service);
        }

        [$executionChain, $executionArounds] = $this->chain($advisors, $executionCtx);
        [$callChain, $callArounds] = null === $callCtx ? [[], []] : $this->chain($advisors, $callCtx);

        $this->declared($executionCtx, $executionPart);
        if (null !== $callCtx && null !== $callPart) {
            $this->declared($callCtx, $callPart);
        }

        $returnType = $function?->getReturnType();
        $declaring = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() : ($function instanceof \ReflectionFunction ? $function->getName() : null);

        return new JoinPointPlan(
            $kind,
            $callKind,
            $signature,
            $executionPart,
            $callPart,
            $enclosing,
            $executionPart->sourceLocation,
            $executionChain,
            $callChain,
            $executionArounds,
            $callArounds,
            null !== $function,
            null === $function ? [] : $function->getParameters(),
            $returnType,
            $declaring,
        );
    }

    /**
     * @param list<Advisor> $advisors
     *
     * @return array{0: list<MethodInterceptorInterface>, 1: list<string>}
     */
    private function chain(array $advisors, StaticMatchContext $ctx): array
    {
        $matched = array_values(array_filter($advisors, fn (Advisor $a): bool => $this->applies($a->pointcut, $ctx)));
        $tokens = [];
        foreach ($this->settings->flowEntries as $entry) {
            if ($this->applies($entry->pointcut, $ctx)) {
                $tokens[] = $entry->aspectClass;
            }
        }
        $tracked = [] !== $tokens;
        if (!$tracked) {
            foreach ($this->trackers as $tracker) {
                if ($this->applies($tracker, $ctx)) {
                    $tracked = true;
                    break;
                }
            }
        }
        if ([] === $matched && !$tracked) {
            return [[], []];
        }

        $arounds = array_values(array_map(static fn (Advisor $a): string => $a->id, array_filter($matched, static fn (Advisor $a): bool => AdviceType::AROUND === $a->adviceType())));

        return [[new FrameInterceptor($this->flow, $tokens), ...$this->chains->chainFor($matched, $ctx, $this->flow)], $arounds];
    }

    /**
     * Raises the declare warnings/errors of the aspects for the join point.
     */
    private function declared(StaticMatchContext $ctx, StaticPart $part): void
    {
        foreach ($this->settings->declareRules as $index => $rule) {
            if (!$this->applies($rule->pointcut, $ctx)) {
                continue;
            }
            $message = sprintf('%s [%s, declared by %s]', $rule->message, $part->toShortString(), $rule->aspectClass);
            if ($rule->isError) {
                throw new DeclareErrorException($message);
            }
            $id = $index.'|'.$part->toShortString();
            if (!isset($this->reported[$id])) {
                $this->reported[$id] = true;
                trigger_error($message, E_USER_WARNING);
            }
        }
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                 $d
     * @param array{class: ?\ReflectionClass<object>, scope: ?\ReflectionClass<object>, function: ?\ReflectionFunctionAbstract, property: ?\ReflectionProperty, caught: ?\ReflectionClass<object>} $shadow
     */
    private static function signature(array $d, JoinPointKind $kind, array $shadow): SignatureInterface
    {
        $function = $shadow['function'];
        $class = $shadow['scope']?->getName() ?? '';

        return match (true) {
            JoinPointKind::CONSTRUCTOR_EXECUTION === $kind => new ConstructorSignature($class, $function instanceof \ReflectionMethod ? $function : null),
            JoinPointKind::INITIALIZATION === $kind => new ConstructorSignature($shadow['class']?->getName() ?? $class),
            $function instanceof \ReflectionMethod => MethodSignature::fromReflection($function),
            $function instanceof \ReflectionFunction => new FunctionSignature($function->getName(), $function),
            JoinPointKind::EXCEPTION_HANDLER === $kind => new CatchClauseSignature(self::str($d['name'] ?? null) ?? '', self::str($d['scope'] ?? null)),
            default => new FieldSignature($class, self::str($d['name'] ?? null) ?? '', $shadow['property']),
        };
    }

    /**
     * @param array{class: ?\ReflectionClass<object>, scope: ?\ReflectionClass<object>, function: ?\ReflectionFunctionAbstract, property: ?\ReflectionProperty, caught: ?\ReflectionClass<object>} $shadow
     */
    private static function executionLocation(?\ReflectionFunctionAbstract $function, array $shadow): ?SourceLocation
    {
        if (null === $function) {
            return null;
        }
        $file = $function->getFileName();
        $line = $function->getStartLine();

        return new SourceLocation(false === $file ? null : $file, false === $line ? null : $line, $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() : null, $function->getName());
    }

    private function enclosingPart(?EnclosingContext $caller): ?StaticPart
    {
        $function = $caller?->function;
        if ($function instanceof \ReflectionMethod) {
            $constructor = '__construct' === strtolower($function->getName());

            return new StaticPart(
                $constructor ? JoinPointKind::CONSTRUCTOR_EXECUTION : JoinPointKind::METHOD_EXECUTION,
                $constructor ? new ConstructorSignature($function->getDeclaringClass()->getName(), $function) : MethodSignature::fromReflection($function),
            );
        }
        if ($function instanceof \ReflectionFunction) {
            return new StaticPart(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature($function->getName(), $function));
        }

        return null;
    }

    // ------------------------------------------------------ advice execution

    /**
     * Runs an advice method inside an `advice-execution` join point when some advisor's pointcut matches it.
     *
     * @param class-string                              $aspectClass
     * @param array<int|string, mixed>                  $values
     * @param \Closure(array<int|string, mixed>): mixed $call
     */
    public function wrapAdvice(JoinPointInterface $joinPoint, string $aspectClass, object $aspect, string $method, array $values, \Closure $call): mixed
    {
        $id = $aspectClass.'::'.$method;
        $state = $this->flow->state();
        if (isset($state->wrapping[$id])) {
            return $call($values);
        }
        if (!\array_key_exists($id, $this->advicePlans)) {
            $this->advicePlans[$id] = $this->adviceChain($aspectClass, $method, $id);
        }
        $chain = $this->advicePlans[$id];
        if (null === $chain) {
            return $call($values);
        }

        $state->wrapping[$id] = true;
        try {
            $reflection = new \ReflectionMethod($aspectClass, $method);
            $signature = AdviceSignature::fromReflection($reflection);
            $part = new StaticPart(JoinPointKind::ADVICE_EXECUTION, $signature, new SourceLocation($reflection->getFileName() ?: null, $reflection->getStartLine() ?: null, $aspectClass, $method));
            $data = new JoinPointData(JoinPointKind::ADVICE_EXECUTION, $aspect, $aspect, $signature, $part, $joinPoint->getStaticPart(), $part->sourceLocation);

            return (new MethodInvocation($data, $values, $chain, $call))->proceed();
        } finally {
            unset($state->wrapping[$id]);
        }
    }

    /**
     * @param class-string $aspectClass
     *
     * @return list<MethodInterceptorInterface>|null null when no advisor applies
     */
    private function adviceChain(string $aspectClass, string $method, string $id): ?array
    {
        $ctx = new StaticMatchContext(JoinPointKind::ADVICE_EXECUTION, new \ReflectionClass($aspectClass), new \ReflectionMethod($aspectClass, $method));
        $matched = array_values(array_filter(
            $this->advisors,
            fn (Advisor $a): bool => ($a->advice?->aspectClass.'::'.$a->advice?->methodName) !== $id && $this->applies($a->pointcut, $ctx),
        ));
        if ([] === $matched) {
            return null;
        }

        return [new FrameInterceptor($this->flow, [], false), ...$this->chains->chainFor($matched, $ctx, $this->flow)];
    }

    // ---------------------------------------------------------------- helpers

    private function newChains(): AdvisorChainFactory
    {
        return new AdvisorChainFactory(new AdvisorRegistry([]), $this->aspects, new ParameterBinder());
    }

    private function clearCaches(): void
    {
        $this->plans = [];
        $this->advicePlans = [];
        $this->classServiceCache = [];
        foreach ($this->advised as $state) {
            $state->clearPlans();
        }
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>|null
     */
    private static function callerOf(array $d): ?array
    {
        if (!\is_array($d['caller'] ?? null)) {
            return null;
        }
        $caller = [];
        foreach ($d['caller'] as $key => $value) {
            $caller[(string) $key] = $value;
        }

        return $caller;
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
