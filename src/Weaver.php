<?php

declare(strict_types=1);

namespace AOP;

use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorFactory;
use AOP\Advisor\IntroductionAdvisor;
use AOP\Advisor\MetadataNamedPointcutResolver;
use AOP\Advisor\Ordered;
use AOP\Aspect\AspectMetadata;
use AOP\Aspect\AspectMetadataReader;
use AOP\Aspect\AspectScope;
use AOP\Attribute\Configurable;
use AOP\Attribute\DeclareError;
use AOP\Attribute\DeclarePrecedence;
use AOP\Attribute\DeclareWarning;
use AOP\Config\ConfigLoader;
use AOP\Config\ConfigResult;
use AOP\Configurable\ConfigurableAspect;
use AOP\Exception\AspectDefinitionException;
use AOP\Exception\WeaverFrozenException;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\PointcutInterface;
use AOP\Runtime\AdvisedInstance;
use AOP\Runtime\CflowEntry;
use AOP\Runtime\DeclareRule;
use AOP\Runtime\EngineBridgeInterface;
use AOP\Runtime\IntroducingEngineInterface;
use AOP\Runtime\NativeEngineBridge;
use AOP\Runtime\NativeRuntime;
use AOP\Runtime\RuntimeSettings;
use AOP\Runtime\ScopedPointcut;
use AOP\TargetSource\TargetSourceInterface;
use AOP\TargetSource\TargetSourceStandIn;

/**
 * The front door: collects aspects and advisors and, once enabled, weaves them through the engine.
 *
 * Every change after enable() makes the engine forget its cached decisions. A frozen Weaver rejects changes to its
 * advice configuration (aspects, advisors, services, include/exclude).
 */
final class Weaver
{
    private readonly NativeRuntime $runtime;
    private readonly AspectMetadataReader $reader;
    private readonly AdvisorFactory $factory;
    private readonly PointcutExpressionCompiler $compiler;

    /** @var array<class-string, array{advisors: list<Advisor>, precedence: list<string>, rules: list<DeclareRule>, entries: list<CflowEntry>, introductions: list<IntroductionAdvisor>, rawIntroductions: list<array{typePattern: string, interface: class-string, defaultImpl: class-string}>}> */
    private array $aspects = [];

    /** @var array<string, Advisor> programmatic advisors by id */
    private array $programmatic = [];

    /** @var list<array{typePattern: string, interface: class-string, defaultImpl: class-string}> programmatic declare-parents rules */
    private array $declaredParents = [];

    /** @var list<TypePattern> */
    private array $includes = [];

    /** @var list<TypePattern> */
    private array $excludes = [];

    /** @var array<string, string> */
    private array $classServices = [];

    private bool $enabled = false;
    private bool $frozen = false;

    public function __construct(private readonly EngineBridgeInterface $engine = new NativeEngineBridge())
    {
        $this->runtime = new NativeRuntime();
        $this->reader = new AspectMetadataReader();
        $this->compiler = new PointcutExpressionCompiler();
        $this->factory = new AdvisorFactory($this->compiler);
    }

    /**
     * @param list<object> $aspects objects of `#[Aspect]` classes
     */
    public static function fromAspects(array $aspects, ?EngineBridgeInterface $engine = null): self
    {
        $weaver = new self($engine ?? new NativeEngineBridge());
        foreach ($aspects as $aspect) {
            $weaver->addAspect($aspect);
        }

        return $weaver;
    }

    /**
     * @param array<string, mixed>            $config        see ConfigLoader for the shape
     * @param (callable(string): object)|null $aspectFactory builds the aspect objects named in the configuration
     */
    public static function fromConfig(array $config, ?callable $aspectFactory = null, ?EngineBridgeInterface $engine = null): self
    {
        $weaver = new self($engine ?? new NativeEngineBridge());
        $weaver->addConfig(ConfigLoader::load($config, $aspectFactory));

        return $weaver;
    }

    /**
     * Registers the aspects of a configuration like addAspect() does, and its programmatic advisors like addAdvisor().
     */
    public function addConfig(ConfigResult $result): void
    {
        $this->assertMutable();
        $instances = $result->aspectInstances();
        foreach ($result->metadata() as $meta) {
            $class = $meta->aspectClass;
            $own = array_values(array_filter($result->aspectAdvisors(), static fn (Advisor $a): bool => $a->advice?->aspectClass === $class));
            $this->registerAspect($meta, $instances[$class] ?? throw new AspectDefinitionException(sprintf('No instance for configured aspect "%s".', $class)), $own, $result->namedPointcutResolver());
        }
        foreach ($result->programmaticAdvisors() as $advisor) {
            $this->addAdvisor($advisor);
        }
        $this->changed();
    }

    /**
     * A stand-in for `$class` whose methods run on the targets of the source (see TargetSourceStandIn).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function targetSource(string $class, TargetSourceInterface $source): object
    {
        [$standIn, $advisor] = TargetSourceStandIn::create($class, $source);
        $this->advised($standIn)->addAdvisor($advisor);
        \assert($standIn instanceof $class);

        return $standIn;
    }

    /**
     * Enables `#[Configurable]`: the configurer runs for objects of annotated classes created with `new`.
     *
     * @param callable(object, Configurable): void $configurer
     */
    public function configurable(callable $configurer): void
    {
        $this->addAdvisor((new ConfigurableAspect($configurer))->toAdvisor());
    }

    /**
     * @throws AspectDefinitionException when the class is not an aspect, is invalid, or is already registered
     * @throws WeaverFrozenException
     */
    public function addAspect(object $aspect): void
    {
        $this->assertMutable();
        $meta = $this->reader->read($aspect::class);
        if ($aspect instanceof Ordered) {
            $meta = new AspectMetadata($meta->aspectClass, $aspect->getOrder(), $meta->namedPointcuts, $meta->advices, $meta->introductions, $meta->scope, $meta->scopePointcut);
        }
        $resolver = new MetadataNamedPointcutResolver([$meta], $this->reader);
        $this->registerAspect($meta, $aspect, $this->factory->fromMetadata($meta, $resolver), $resolver);
        $this->changed();
    }

    /**
     * @param object|\Closure(): object $instance
     * @param list<Advisor>             $advisors the advisors of the aspect, built from its metadata
     */
    private function registerAspect(AspectMetadata $meta, object $instance, array $advisors, MetadataNamedPointcutResolver $resolver): void
    {
        $class = $meta->aspectClass;
        if (isset($this->aspects[$class])) {
            throw new AspectDefinitionException(sprintf('Aspect "%s" is already registered.', $class));
        }
        $reflection = new \ReflectionClass($class);

        [$restriction, $entry] = $this->scopePointcut($meta);
        $scoped = [];
        foreach ($advisors as $advisor) {
            $scoped[] = AspectScope::SINGLETON === $meta->scope ? $advisor : new Advisor(
                $advisor->id,
                $advisor->advice,
                $advisor->order,
                new ScopedPointcut($advisor->pointcut, $class, $meta->scope, $restriction, $this->runtime->flowStack()),
            );
        }

        $rules = [];
        foreach ($reflection->getAttributes(DeclareWarning::class) as $attribute) {
            $declaration = $attribute->newInstance();
            $rules[] = new DeclareRule($class, $this->compiler->compile($declaration->pointcut, [], $class, $resolver), $declaration->message, false);
        }
        foreach ($reflection->getAttributes(DeclareError::class) as $attribute) {
            $declaration = $attribute->newInstance();
            $rules[] = new DeclareRule($class, $this->compiler->compile($declaration->pointcut, [], $class, $resolver), $declaration->message, true);
        }
        $precedence = array_map(static fn (\ReflectionAttribute $a): string => $a->newInstance()->aspects, $reflection->getAttributes(DeclarePrecedence::class));

        $this->aspects[$class] = [
            'advisors' => $scoped,
            'precedence' => $precedence,
            'rules' => $rules,
            'entries' => null === $entry ? [] : [$entry],
            'introductions' => $this->factory->introductionsFromMetadata($meta),
            'rawIntroductions' => array_map(
                static fn (Aspect\IntroductionDefinition $i): array => ['typePattern' => $i->typePattern, 'interface' => $i->interface, 'defaultImpl' => $i->defaultImpl],
                $meta->introductions,
            ),
        ];
        $this->runtime->aspects()->register($class, $instance, $meta->scope);
    }

    /**
     * Removing an aspect that is not registered does nothing.
     *
     * @param class-string $aspectClass
     */
    public function removeAspect(string $aspectClass): void
    {
        $this->assertMutable();
        $aspectClass = ltrim($aspectClass, '\\');
        if (!isset($this->aspects[$aspectClass])) {
            return;
        }
        unset($this->aspects[$aspectClass]);
        $this->runtime->aspects()->remove($aspectClass);
        $this->changed();
    }

    /**
     * @throws AspectDefinitionException when an advisor with the same id exists
     */
    public function addAdvisor(Advisor $advisor): void
    {
        $this->assertMutable();
        foreach ($this->allAdvisors() as $existing) {
            if ($existing->id === $advisor->id) {
                throw new AspectDefinitionException(sprintf('An advisor with id "%s" already exists.', $advisor->id));
            }
        }
        $this->programmatic[$advisor->id] = $advisor;
        $this->changed();
    }

    /**
     * Removes a programmatic advisor or a single advice of an aspect (id `AspectClass::method`). Unknown ids are ignored.
     */
    public function removeAdvisor(string $id): void
    {
        $this->assertMutable();
        $removed = isset($this->programmatic[$id]);
        unset($this->programmatic[$id]);
        foreach ($this->aspects as $class => $aspect) {
            $kept = array_values(array_filter($aspect['advisors'], static fn (Advisor $a): bool => $a->id !== $id));
            if (\count($kept) !== \count($aspect['advisors'])) {
                $this->aspects[$class]['advisors'] = $kept;
                $removed = true;
            }
        }
        if ($removed) {
            $this->changed();
        }
    }

    /**
     * All advisors, outermost first.
     *
     * @return list<Advisor>
     */
    public function advisors(): array
    {
        return $this->runtime->advisors();
    }

    /**
     * Advice for one object only; see AdvisedInstance.
     */
    public function advised(object $instance): AdvisedInstance
    {
        return $this->runtime->advised($instance, function (): void {
            $this->engineReset();
        }, fn (): bool => $this->frozen);
    }

    public function registerService(object $instance, string $serviceName): void
    {
        $this->assertMutable();
        $this->runtime->registerInstanceService($instance, $serviceName);
        $this->engineReset();
    }

    /**
     * @param class-string $class
     */
    public function registerServiceClass(string $class, string $serviceName): void
    {
        $this->assertMutable();
        $this->classServices[ltrim($class, '\\')] = $serviceName;
        $this->changed();
    }

    /**
     * Only types matching an include pattern are woven (all types when there is none), except those matching an exclude pattern.
     * Functions are matched by their qualified name. A trailing `+` includes subtypes.
     */
    public function include(string $typePattern): void
    {
        $this->assertMutable();
        $this->includes[] = self::pattern($typePattern);
        $this->changed();
    }

    public function exclude(string $typePattern): void
    {
        $this->assertMutable();
        $this->excludes[] = self::pattern($typePattern);
        $this->changed();
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function enable(): void
    {
        if ($this->enabled) {
            return;
        }
        $this->configure();
        $this->pushIntroductions();
        $this->engine->register($this->runtime->match(...), $this->runtime->dispatch(...));
        $this->enabled = true;
    }

    public function disable(): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->engine->unregister();
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Programmatic `#[DeclareParents]` (AspectJ `declare parents`): classes matching $typePattern that are compiled
     * after this call implement $interface; methods they do not declare delegate to a $defaultImpl instance per object.
     *
     * @param class-string $interface
     * @param class-string $defaultImpl
     */
    public function declareParents(string $typePattern, string $interface, string $defaultImpl): void
    {
        $this->assertMutable();
        if (!interface_exists($interface)) {
            throw new AspectDefinitionException(sprintf('declareParents(): "%s" is not an interface.', $interface));
        }
        if (!class_exists($defaultImpl) || !is_a($defaultImpl, $interface, true)) {
            throw new AspectDefinitionException(sprintf('declareParents(): the default implementation "%s" must be a class implementing %s.', $defaultImpl, $interface));
        }
        $this->declaredParents[] = ['typePattern' => trim($typePattern), 'interface' => $interface, 'defaultImpl' => $defaultImpl];
        $this->changed();
    }

    public function compiler(): PointcutExpressionCompiler
    {
        return $this->compiler;
    }

    /**
     * The `#[DeclareParents]` and declareParents() rules handed to the engine.
     *
     * @return list<array{typePattern: string, interface: class-string, defaultImpl: class-string}>
     */
    public function introductionRules(): array
    {
        $rules = $this->declaredParents;
        foreach ($this->aspects as $aspect) {
            array_push($rules, ...$aspect['rawIntroductions']);
        }

        return $rules;
    }

    /**
     * @return array{0: ?PointcutInterface, 1: ?CflowEntry}
     */
    private function scopePointcut(AspectMetadata $meta): array
    {
        $expression = $meta->scopePointcut;
        $needs = [AspectScope::PER_CFLOW, AspectScope::PER_CFLOW_BELOW, AspectScope::PER_TYPE_WITHIN];
        if (null === $expression || '' === trim($expression)) {
            if (\in_array($meta->scope, $needs, true)) {
                throw new AspectDefinitionException(sprintf('Aspect "%s" uses scope %s, which needs #[Aspect(pointcut: ...)].', $meta->aspectClass, $meta->scope->value));
            }

            return [null, null];
        }
        if (AspectScope::SINGLETON === $meta->scope) {
            throw new AspectDefinitionException(sprintf('Aspect "%s" is a singleton, so #[Aspect(pointcut: ...)] has no meaning.', $meta->aspectClass));
        }
        $pointcut = $this->compiler->compile(AspectScope::PER_TYPE_WITHIN === $meta->scope ? 'within('.$expression.')' : $expression, [], $meta->aspectClass, new MetadataNamedPointcutResolver([$meta], $this->reader));
        $entry = AspectScope::PER_CFLOW === $meta->scope || AspectScope::PER_CFLOW_BELOW === $meta->scope ? new CflowEntry($meta->aspectClass, $meta->scope, $pointcut) : null;

        return [$pointcut, $entry];
    }

    /**
     * @return list<Advisor>
     */
    private function allAdvisors(): array
    {
        $all = array_values($this->programmatic);
        foreach ($this->aspects as $aspect) {
            array_push($all, ...$aspect['advisors']);
        }

        return $all;
    }

    private function configure(): void
    {
        $precedence = [];
        $rules = [];
        $entries = [];
        $introductions = [];
        foreach ($this->aspects as $aspect) {
            array_push($precedence, ...$aspect['precedence']);
            array_push($rules, ...$aspect['rules']);
            array_push($entries, ...$aspect['entries']);
            array_push($introductions, ...$aspect['introductions']);
        }
        foreach ($this->declaredParents as $rule) {
            $introductions[] = new IntroductionAdvisor(self::class, self::pattern($rule['typePattern']), $rule['interface'], $rule['defaultImpl'], PHP_INT_MAX);
        }
        $this->runtime->configure(new RuntimeSettings($this->allAdvisors(), $precedence, $rules, $entries, $introductions, $this->includes, $this->excludes, $this->classServices));
    }

    private function changed(): void
    {
        $this->configure();
        if ($this->enabled) {
            $this->pushIntroductions();
        }
        $this->engineReset();
    }

    private function pushIntroductions(): void
    {
        if ($this->engine instanceof IntroducingEngineInterface) {
            $this->engine->setIntroductions($this->introductionRules());
        }
    }

    private function engineReset(): void
    {
        if ($this->enabled) {
            $this->engine->reset();
        }
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new WeaverFrozenException('The Weaver is frozen; its advice configuration can no longer change.');
        }
    }

    private static function pattern(string $pattern): TypePattern
    {
        $pattern = trim($pattern);

        return new TypePattern(rtrim($pattern, '+'), false, str_ends_with($pattern, '+'));
    }
}
