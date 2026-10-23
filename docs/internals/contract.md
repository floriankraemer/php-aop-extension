# Internal contract: C engine ↔ PHP runtime

This file is binding for every workstream. Change it only together with every side that depends on it.

Namespaces: everything lives under `AOP\`. The C extension registers `AOP\Engine` and `AOP\OriginalCall`
(signatures in `ext/aop.stub.php`); everything else is the PHP package in `src/` (PSR-4 `AOP\` → `src/`).
The PHP package must never declare `AOP\Engine` or `AOP\OriginalCall`.

## 1. Native API (C → PHP)

```php
namespace AOP;

final class Engine
{
    public const ADVISE = 1;            // dispatch this join point to the dispatcher
    public const TRACK = 2;             // dispatch it too, it has no advice but feeds cflow(); C treats ADVISE|TRACK alike
    public const CALLER_SENSITIVE = 4;  // the decision depends on the caller: ask again per (callee, caller function)

    /**
     * @param callable(array $joinPoint): int                                                   $matcher
     * @param callable(array $joinPoint, ?object $this, array $args, OriginalCall $original): mixed $dispatcher
     */
    public static function register(callable $matcher, callable $dispatcher): void;
    public static function unregister(): void;
    public static function isRegistered(): bool;
    /** Forgets every cached matcher decision (call after advisors or services change). */
    public static function reset(): void;
    /** Experimental streams (G), see §6. */
    public static function setIntroductions(array $rules): void;
    public static function fieldInterceptionAvailable(): bool;
}

final class OriginalCall
{
    private function __construct();               // only C creates instances
    public function __invoke(array $args): mixed;  // runs the original code, bypassing interception once
}
```

### 1.1 The matcher

Called by C on a decision-cache miss, with interception of everything suspended while it runs (re-entrancy guard).
It receives a **join point descriptor** (§2) **without** `caller.object`, `caller.line`, `outermost`, `exception`
and `value` (those differ per call and are never part of a decision). It returns a bit set of `Engine::*`.

Protocol for `CALLER_SENSITIVE`:
1. C asks with `caller => null`. The result is cached per (runtime class, function) for methods, per function for
   functions, per (class, property, kind) for fields, per (function, catch class) for handlers.
2. If the result contains `CALLER_SENSITIVE`, C asks again for each distinct caller function, with
   `caller` filled (class/scope/function/file, not line/object), and caches that answer per (callee, caller function).
   The second answer's `CALLER_SENSITIVE` bit is ignored.

Exceptions thrown by the matcher are reported as `E_WARNING` and the decision is 0 (not cached), so a broken
aspect never kills unrelated code paths silently.

### 1.2 The dispatcher

Called for every join point whose decision is non-zero, with the advised frame still on the stack
(so `debug_backtrace()` and exception traces show the advised function).
Receives the full descriptor, `$this` (null for static methods, functions, ctor-less `initialization`), the raw
argument array (§3) and an `OriginalCall`. Its return value becomes the result of the call; an exception thrown
by it propagates to the caller exactly like one thrown by the original code.
The PHP side type-hints the original as `callable` (tests pass a `\Closure`; see `tests/Support/FakeEngine.php`).

### 1.3 OriginalCall

- `__invoke(array $args)`: int keys are positional (in order), string keys named arguments.
  Elements that are PHP references are passed as references (by-ref parameters write through to the caller).
- Calls the very function/method that was intercepted with the same `$this`/called scope, ignoring visibility
  (private and protected work), and runs it **without** interception of that one call (other nested calls are
  intercepted normally). May be called zero, one or many times, also after the dispatcher returned (it holds a
  reference to `$this`).
- Fields: `field-get` → `__invoke([])` returns the property value; `field-set` → `__invoke([$value])` writes.
  `exception-handler` and ctor-less `initialization` → no-op returning null.

### 1.4 Never intercepted by C

- Anything while no dispatcher is registered, or while the matcher runs.
- Classes in namespace `AOP\` and calls whose **caller** frame belongs to a class in `AOP\` (the runtime itself).
- Closures, generator **resumption** (only the call that creates the generator is a join point), eval/include frames,
  functions returning by reference.
- Internal functions compiled to dedicated opcodes (`strlen`, `count`, `is_*`, …) and PHP 8.4+ frameless calls.

## 2. Join point descriptor (array)

| Key | Type | Meaning |
|---|---|---|
| `kind` | `string` | A C kind (below). |
| `class` | `?string` | Runtime class: `get_class($this)` for instance calls, the called class (`static::class`) for static calls; null for functions. |
| `scope` | `?string` | Declaring class of the executed code (`func->common.scope`; the using class for trait methods); null for functions. |
| `name` | `string` | Method/function name as declared (case preserved; namespaced for functions); property name for fields; caught class for handlers. |
| `static` | `bool` | Static method. |
| `internal` | `bool` | Internal (C) function or method. |
| `outermost` | `bool` | Constructors only (dispatch only): this is the object's outermost constructor (not a `parent::__construct()`). |
| `caller` | `?array` | Enclosing code: `class` (?string, runtime class of the caller's `$this` or its called scope), `scope` (?string), `function` (?string, null for top-level code), `file` (?string), `line` (?int, dispatch only), `object` (?object, dispatch only). Null when the caller is internal code or unknown. |
| `exception` | `?\Throwable` | `exception-handler` only. |
| `value` | `mixed` | `field-set` only (also passed as `$args[0]`). |

C kinds (strings equal to the `AOP\JoinPoint\JoinPointKind` values):
`method-execution`, `constructor-execution` (name `__construct`, both user and internal classes),
`function-execution` (user and internal functions), `initialization` (ctor-less classes only, from `new`; see §6),
`field-get`, `field-set`, `exception-handler`.

Call join points (`method-call`, `constructor-call`, `function-call`) and `advice-execution` are **derived by the PHP
runtime**: a call join point is the execution descriptor seen from `caller`. The runtime builds the chain as
`[call-kind interceptors] → [execution-kind interceptors] → OriginalCall`.

## 3. Arguments array (C → dispatcher)

Raw, as passed by the caller: declared parameters that received a value, in order (int keys from 0), then extra
positional arguments, then extra named arguments (string keys, for variadics). Omitted trailing optional parameters
are **absent**. By-reference parameters are PHP references to the caller's variables.
The runtime normalises this to the library shape before building the join point:
declared parameters in order with defaults filled in (via reflection), then the variadic tail with its original keys.

## 4. PHP model shapes (owned by stream B, used by D/E/F)

```php
namespace AOP\JoinPoint;

enum JoinPointKind: string {
    case METHOD_EXECUTION = 'method-execution';   case METHOD_CALL = 'method-call';
    case CONSTRUCTOR_EXECUTION = 'constructor-execution'; case CONSTRUCTOR_CALL = 'constructor-call';
    case FUNCTION_EXECUTION = 'function-execution'; case FUNCTION_CALL = 'function-call';
    case INITIALIZATION = 'initialization';
    case FIELD_GET = 'field-get'; case FIELD_SET = 'field-set';
    case EXCEPTION_HANDLER = 'exception-handler';
    case ADVICE_EXECUTION = 'advice-execution';
    public function isCall(): bool; public function isExecution(): bool;
}

interface SignatureInterface {            // MethodSignature, ConstructorSignature, FunctionSignature, FieldSignature,
    public function getName(): string;    // CatchClauseSignature, AdviceSignature (extends MethodSignature)
    public function getDeclaringTypeName(): ?string;
    public function toShortString(): string;  // UserService::find(..), fmt(..), UserService::$count, catch(RuntimeException)
    public function toLongString(): string;
}

final readonly class SourceLocation { public ?string $file; public ?int $line; public ?string $class; public ?string $function; }  // all ctor args optional; toString()
final readonly class StaticPart {    // toShortString(): "method-execution(UserService::find(..))", toLongString()
    public function __construct(public JoinPointKind $kind, public SignatureInterface $signature, public ?SourceLocation $sourceLocation = null) {}
}
// Signatures (all final readonly except MethodSignature, which AdviceSignature extends):
//   MethodSignature(string $className, string $methodName, \ReflectionMethod $method)  + static fromReflection()
//   ConstructorSignature(string $className, ?\ReflectionMethod $constructor = null)
//   FunctionSignature(string $functionName, ?\ReflectionFunction $function = null)
//   FieldSignature(string $className, string $propertyName, ?\ReflectionProperty $property = null)
//   CatchClauseSignature(string $caughtType, ?string $declaringClass = null)
//   AdviceSignature(string $className, string $methodName, \ReflectionMethod $method)   // short: adviceexecution(Aspect::method(..))

interface JoinPointInterface {
    public function getKind(): JoinPointKind;
    public function getThis(): ?object;      // executing object; for call kinds the CALLER's object
    public function getTarget(): ?object;    // object the member belongs to; null for static/functions
    public function getArgs(): array;
    public function getSignature(): SignatureInterface;
    public function getStaticPart(): StaticPart;
    public function getEnclosingStaticPart(): ?StaticPart;   // the caller
    public function getSourceLocation(): ?SourceLocation;    // call site
    public function toShortString(): string;
    public function toLongString(): string;
}
interface ProceedingJoinPointInterface extends JoinPointInterface { public function proceed(?array $args = null): mixed; }
```

`MethodInvocation` (kept name, generalised) is constructed as
`new MethodInvocation(JoinPointData $data, array $args, list<MethodInterceptorInterface> $interceptors, \Closure $targetInvoker)`
where `JoinPointData` is a readonly value object, constructed as
`new JoinPointData(JoinPointKind $kind, ?object $thisObject, ?object $target, SignatureInterface $signature, StaticPart $staticPart, ?StaticPart $enclosingStaticPart = null, ?SourceLocation $sourceLocation = null)`
(`$this` cannot be a parameter or promoted property name, hence `thisObject`).
Advice parameters typed `StaticPart` bind the join point's static part (AspectJ `thisJoinPointStaticPart`).

```php
namespace AOP\Pointcut;

final class StaticMatchContext {          // the "shadow": everything known without running the code
    public function __construct(
        public readonly JoinPointKind $kind,
        public readonly ?\ReflectionClass $targetClass,                 // runtime class; null for functions
        public readonly ?\ReflectionFunctionAbstract $function = null,  // method/ctor/function; null for fields/handlers
        public readonly ?\ReflectionProperty $property = null,          // fields
        public readonly ?\ReflectionClass $caughtType = null,           // handlers
        public readonly ?EnclosingContext $caller = null,               // AOP\Pointcut\EnclosingContext(?ReflectionClass $class = null, ?ReflectionFunctionAbstract $function = null, ?string $file = null)
        public readonly ?string $serviceName = null,
        public readonly array $introducedInterfaces = [],
    ) {}
}

interface PointcutInterface {
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool;                  // cheap prefilter
    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool;   // full static decision
    public function isRuntime(): bool;
    public function matchesRuntime(RuntimeMatchContext $ctx): bool;
    /** @return array<string, BindingSpec> */
    public function bindings(): array;
    public function isCallerSensitive(): bool;   // static result depends on $ctx->caller. true: within, @within, this (all read the caller for call kinds, field accesses and handlers); false: execution, target, args, @args, @annotation, @target, service. And/Or: either child; Not: the child.
}

final class RuntimeMatchContext {
    public function __construct(
        public readonly JoinPointInterface $joinPoint,
        public readonly StaticMatchContext $staticContext,
        public readonly ControlFlowInterface $controlFlow = new EmptyControlFlow(),
    ) {}
    // helpers: this(), target(), args(), function(), targetClass(); bind(string, mixed), bound()
}

interface ControlFlowInterface {
    /** Active join points of the current Fiber, outermost first, the current one last. @return list<JoinPointInterface> */
    public function stack(): array;
}
final class EmptyControlFlow implements ControlFlowInterface {}   // stack() === []
```

Designator semantics per kind follow AspectJ: `execution` → *-execution kinds; `call` → *-call kinds; `within`/`withincode`
look at the executing code for execution kinds and at the caller for call kinds; `this` is the executing (call kinds:
calling) object, `target` the callee object; `args` is the argument list (field-set: the value; handler: the exception).
A designator that cannot apply to a kind returns false (never throws). In detail:
`execution` only on the three execution kinds (a function pattern has no `Type::`, may be namespaced, `public`/`protected` and a declaring type never match
functions; a constructor is the method name `__construct`, so `*` also matches it, and the return type pattern `*` matches it); `within`/`@within` use the
declaring class of the executing method for execution kinds (functions are within nothing) and the caller's class (the declaring class of the caller's
method when known) for everything else, false while the caller is unknown; `this` is false for static methods, functions, static callers and while the
caller is unknown; `target` is false for static members, functions and handlers; `@annotation` reads the method/function/constructor or, for
field kinds, the property; `args` on `field-get` takes no arguments, on `field-set` one (the property type constrains it), on handlers one.
Runtime: `this()`/`target()` bindings fail the match when the object is null.

```php
namespace AOP\Advisor;

final readonly class Advisor {   // aspect-declared OR programmatic: exactly one of $advice / $interceptor is set
    public function __construct(
        public string $id,                         // AspectClass::method, or a caller-chosen id
        public ?AdviceDefinition $advice,
        public int $order,
        public PointcutInterface $pointcut,
        public ?MethodInterceptorInterface $interceptor = null,
        public ?AdviceType $type = null,           // ordering slot for programmatic advisors (defaults to AROUND)
    ) {}
}
```
The constructor throws `AspectDefinitionException` unless exactly one of `$advice`/`$interceptor` is set; `source()` is the aspect class or the id,
`adviceType()` the advice's type, else `$type`, else AROUND.
`AdvisorChainFactory::chainFor(list<Advisor>, StaticMatchContext, ControlFlowInterface = new EmptyControlFlow())` uses `$interceptor` as-is when present
(still wrapped in `RuntimeGuardInterceptor` when the pointcut is runtime; it takes the same optional control flow). `AdvisorOrderComparator` sorts by order,
then `source()`, then `adviceType()->precedence()`, then declaration index (0 for programmatic advisors).

```php
namespace AOP\Runtime;
final class AopContext {   // innermost join point of the current call stack
    public static function currentJoinPoint(): ?JoinPointInterface;
    public static function currentObject(): ?object;            // currentJoinPoint()?->getThis()
    public static function push(JoinPointInterface $jp): void;  // @internal
    public static function pop(): void;                         // @internal
}
final class ReturnTypeCheck {
    // `static` = $thisObject::class for instance methods, else $calledClass; `self` = $declaringClass
    public static function accepts(?\ReflectionType $type, mixed $value, ?object $thisObject, string $declaringClass, ?string $calledClass = null): bool;
}
```
Advice parameters typed `AOP\JoinPoint\StaticPart` are not pointcut bindings; `BindingPlan` fills them with `$jp->getStaticPart()`.

## 5. Weaver extension points (owned by stream D, used by F)

```php
namespace AOP;
final class Weaver {
    public static function fromAspects(array $aspects, ?Runtime\EngineBridgeInterface $engine = null): self;
    public function addAspect(object $aspect): void;
    public function removeAspect(string $aspectClass): void;
    public function addAdvisor(Advisor\Advisor $advisor): void;
    public function removeAdvisor(string $id): void;
    /** @return list<Advisor\Advisor> */
    public function advisors(): array;
    public function advised(object $instance): Runtime\AdvisedInstance;   // per-instance advisors (Spring "Advised")
    public function registerService(object $instance, string $serviceName): void;
    public function registerServiceClass(string $class, string $serviceName): void;
    public function include(string $typePattern): void;  public function exclude(string $typePattern): void;
    public function freeze(): void;  public function isFrozen(): bool;
    public function enable(): void;  public function disable(): void;  public function isEnabled(): bool;
    public function compiler(): Pointcut\Expression\PointcutExpressionCompiler;  // for programmatic expression pointcuts
}

namespace AOP\Runtime;
interface EngineBridgeInterface {           // NativeEngineBridge talks to \AOP\Engine; tests use FakeEngine
    public function register(callable $matcher, callable $dispatcher): void;
    public function unregister(): void;
    public function reset(): void;
}
```
Every mutating Weaver call after `enable()` calls `EngineBridgeInterface::reset()`. A frozen Weaver throws
`AOP\Exception\WeaverFrozenException` on mutation.

## 6. Experimental engine features (stream G)

- **Fields**: on `new` of a class, C asks the matcher with `kind => 'field-get', name => '*'` (a class probe).
  On a non-zero answer, objects of that class get wrapped handlers. Each property access then asks for the
  concrete (class, property, kind). Objects created before `Engine::register()`, or created without `new`/`clone`
  (`unserialize`, `newInstanceWithoutConstructor`), are not covered.
- **Handlers**: `exception-handler` is before-only: the dispatcher's return value is ignored, `OriginalCall` is a no-op.
- **Introductions**: `Engine::setIntroductions(list<array{typePattern: string, interface: class-string, defaultImpl: class-string}>)`
  must be called before the target class is compiled. Classes compiled afterwards get `implements <interface>`,
  and every interface method the class does not declare delegates to a lazily created `defaultImpl` instance.
  With opcache the woven result is cached with the file, so changing the rules needs an opcache reset.

## 7. FakeEngine (tests)

`AOP\Tests\Support\FakeEngine` implements `EngineBridgeInterface`. `call(object|string $target, string $method, array $args = [], ?array $caller = null)`
and `callFunction(string $function, array $args = [], ?array $caller = null)` build the same descriptors and
args as C (§2, §3), apply the same decision and caller-sensitive protocol (§1.1), and pass a `\Closure` that
invokes the original through reflection. So PHP streams test the runtime end to end without the compiled
extension. E2E tests in `tests/E2E` use the real extension (`extension_loaded('aop')`, else skipped).

## Runtime notes (stream D)

Decisions where the contract was silent.

**Eligibility.** The matcher never advises abstract methods, classes carrying `#[Aspect]` (as runtime or declaring class), enums,
anonymous classes, and anything in the `AOP\` namespace except `AOP\Tests\` (so FakeEngine tests can use fixtures; the C engine itself
never intercepts `AOP\Tests\`, so E2E fixtures must live elsewhere, and their callers too: a call made from code in `AOP\` is not intercepted).
Everything else is advisable. A matcher exception inside our own reflection yields 0.

**Matcher.** `ADVISE` when any advisor, declare rule or per-instance class marker matches the execution kind or the derived call kind
(method/constructor/function execution -> method/constructor/function call). `TRACK` when only a cflow feeds on it: pointcuts implementing
`AOP\Runtime\ControlFlowConsumer::controlFlowPointcut()` (stream E's `cflow`/`cflowbelow` must implement it; the runtime finds consumers
inside And/Or/Not/etc. by walking pointcut properties) and the entry pointcuts of percflow aspects. `CALLER_SENSITIVE` (only on the
caller-less first ask) when a caller-sensitive pointcut passes `matchesClass` for the call kind (or for the kind itself on fields/handlers).
Execution-kind contexts carry `caller = null`; call-kind contexts carry the `EnclosingContext` of the caller. A `constructor-execution`
that is not `outermost` has no call kind. Call-kind chains only exist when the descriptor has a caller.

**Services.** Matcher: class-level service of the runtime class or nearest parent, plus every name registered for an instance of that exact
class (a superset, resolved per object in the dispatcher). Instance services win over class services in the dispatcher.

**Include/exclude.** Type patterns (`+` for subtypes) are matched against the declaring class (`scope`) of the executed code, for functions
against the function name; exclude wins; no include = everything.

**Arguments.** Normalised to declared parameters with defaults filled in (stopping at the first parameter without an available default
or a by-ref one) plus the extras with their keys; references are kept. The original receives the normalised array (so `func_num_args()`
sees the filled defaults). With no interceptors the raw array is passed through untouched.

**Chains.** Every non-empty chain starts with a `FrameInterceptor` (pushes the join point on the per-Fiber cflow stack and on
`AopContext`, which is also per Fiber). Call join points are only pushed when a call chain exists. The stack includes the current join
point. `this` of a call kind is the caller object, `target` the callee; the enclosing static part is the caller's execution static part
(null for top-level code). Plans are cached per (kind, class, scope, name, instance service, caller class/scope/function, outermost);
per-instance plans live in the `AdvisedInstance`. Around results are checked against the declared return type for both chains.

**Ordering.** An aspect's order is `Ordered::getOrder()` of the registered instance, else `#[Order]`, else `PHP_INT_MAX`. Advisors of one
source (aspect class, or programmatic id) run as a block, blocks by (order, name). `#[DeclarePrecedence('A, B, *')]` (repeatable, patterns
may end in `+`) adds "earlier before later" constraints, applied by a stable topological sort over the default sequence; it overrides
`#[Order]` between the aspects it relates; contradictions throw `AspectDefinitionException`. It applies to every source, not only aspects.

**Instantiation models.** `#[Aspect(scope: ..., pointcut: ...)]`. The registered object is the singleton and the clone prototype; other
instances are clones. perthis: per `getThis()` (for call kinds the caller). pertarget: per `getTarget()`. Static members and functions have
no this/target, so they use the registered object. `pointcut` on perthis/pertarget restricts the advised join points statically.
pertypewithin (pointcut = type pattern, required): one instance per type the join point lexically belongs to (declaring class of the
executed code, caller's class for call kinds), advice limited to matching types. percflow/percflowbelow (pointcut required): a token is
pushed when a join point matching the entry pointcut (static part only) is entered; one instance per token; advice is skipped outside
the flow. percflowbelow does not bind at the entry join point itself.

**Declare warning/error.** Checked when a plan is built, i.e. at the first dispatch of a join point (the matcher answers ADVISE for matches
so the dispatcher sees them). Warnings go through `trigger_error(E_USER_WARNING)` once per rule and join point; errors throw
`DeclareErrorException` on every call (the plan is not cached).

**adviceexecution.** `AdviceInvoker` asks the aspect resolver (`AdviceExecutionWrapper`) to run each advice call. Advisors matching kind
`ADVICE_EXECUTION` with the aspect method wrap it (target = this = the aspect object, args = the bound advice arguments). An advisor never
advises its own advice, and an advice method already being wrapped is not wrapped again (per Fiber), so wildcard pointcuts terminate.

**Per-instance advice.** `AdvisedInstance` (not final) is stored in a WeakMap; once an instance of a class has advisors the matcher answers
ADVISE for every eligible join point of that class. Its advisors are merged and ordered with the global ones. `removeAdvisor`/`removeAspect`
of unknown ids do nothing; duplicates throw. Freezing a Weaver freezes its instances; `AdvisedInstance::freeze()` freezes one.

**Introductions.** `EngineBridgeInterface` is unchanged (FakeEngine is final and may not be edited); bridges that accept
`#[DeclareParents]` rules implement `IntroducingEngineInterface::setIntroductions()`. `NativeEngineBridge` does; the Weaver calls it on
`enable()` and after every change while enabled. `tests/Support/RecordingEngine` records the rules.

**NativeEngineBridge** can be constructed without the extension; `register()` etc. then throw `AspectDefinitionException` naming ext-aop.
