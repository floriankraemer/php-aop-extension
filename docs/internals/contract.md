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
Receives the full descriptor, `$this` (null for static methods and functions; the new object for ctor-less `initialization`; the enclosing code's `$this` for `exception-handler`), the raw
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
- Internal functions compiled to dedicated opcodes (`strlen`, `count`, `is_*`, …). (PHP 8.4+ frameless calls are not
  emitted while the hooks are installed, so those functions *are* intercepted; see "Compat notes (stream H)".)
- PHP 8.4+ property hook bodies (`$prop::get`/`$prop::set`); their accesses are field join points (§6).

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
  An exception thrown by the dispatcher replaces the caught one (AspectJ: it propagates from the handler).
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
(method/constructor/function execution -> method/constructor/function call). `TRACK` when only a cflow feeds on it: `ControlFlowPointcuts::of($advisor->pointcut)` (stream E) contains an inner pointcut whose static half matches, or the join point is the entry of a percflow aspect. `ScopedPointcut` forwards `cflowPointcuts()`. `CALLER_SENSITIVE` (only on the
caller-less first ask) when a caller-sensitive pointcut passes `matchesClass` for the call kind (or for the kind itself on fields/handlers).
Execution-kind contexts carry `caller = null`; call-kind contexts carry the `EnclosingContext` of the caller. A `constructor-execution`
that is not `outermost` has no call kind and its join point reports `isOutermost() === false` (`JoinPointData::$outermost`, `MethodInvocation implements OutermostAwareInterface`). Call-kind chains only exist when the descriptor has a caller.

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

## Engine notes (stream A)

Decisions the sections above leave open, as implemented by `ext/` for PHP 8.3 (tests: `ext/tests/0*.phpt`).

- **Pass-through order** (both hooks): `AOP_G(bypass) == func` (cleared, then pass) → no pair registered, matcher
  running, or an exception pending → generator resumption / top-level, include, eval code → closures, trampolines,
  return-by-reference → callee scope or runtime class in `AOP\` (functions: name in `AOP\`) → caller in `AOP\` →
  decision. Matching on `AOP\` is case-insensitive.
- **"Caller in `AOP\`"** walks down the stack through internal frames to the nearest user frame, so a call the
  runtime makes through `call_user_func`, `array_map`, `ReflectionMethod::invoke`, … is still the runtime's.
  Because `OriginalCall::__invoke` is itself an `AOP\` frame, the call it makes is never intercepted even without
  the bypass.
- **`caller`** is the nearest frame below the advised one. If it runs internal code (e.g. `array_map`,
  `ReflectionMethod::invoke`, a non-inlined `call_user_func`) it is `null`, as the table says. Note that
  `call_user_func()` with a literal callable in a non-namespaced (or `\call_user_func`) call is compiled to
  opcodes, so there the user code is the caller. Top-level/include/eval code: `function => null`; `class`/`scope`
  are those of the including method if any. `line` is the line of the call in the caller.
- **CALLER_SENSITIVE**: with an internal or missing caller the second question is not asked; the first answer
  without the `CALLER_SENSITIVE` bit is used (it was already asked with `caller => null`). The second-level cache
  key is (callee runtime class, callee function, caller runtime class, caller scope, caller code), where the caller
  code is the calling function's opcodes (all closure objects of one declaration share them) or, for
  top-level/include/eval code, its file name.
- The matcher result is cast to int. A matcher exception becomes the warning
  `AOP\Engine: the matcher threw <class> (<message>) for <Class::method>(); the call is not intercepted`.
  If an error handler turns that warning into an exception, the intercepted call fails with it.
- **Arguments**: the VM fills parameters skipped by named arguments with their defaults *before* the function is
  entered, so `f(1, c: 3)` for `f($a, $b = 'B', $c = 'C')` gives `[1, 'B', 3]` (int keys). Only omitted
  *trailing* parameters are absent. Should a slot still be undefined, later parameters are keyed by name.
  Values are raw: no coercion or type check has run (it runs when the original runs; never if nobody proceeds).
- **Fake closures** (`$obj->m(...)`, `Closure::fromCallable([$obj, 'm'])`, `strrev(...)`) are advised as the
  underlying method/function (same decision and descriptor). Real closures and arrow functions are not.
- **Magic calls**: the `__call`/`__callStatic` trampoline is not a join point, the `__call`/`__callStatic`
  method itself is (name `__call`, args `[$name, $arguments]`).
- **Results**: the dispatcher's return value is not checked against the declared return type; a returned
  reference is dereferenced; constructors ignore it. After an exception the call has no result.
- A dispatcher (or matcher) that is a non-closure user method is never intercepted itself.
- `outermost` is false only when the immediate caller frame is a `__construct` running on the same object.
- `register()` replaces a registered pair and clears the decision cache; with `aop.enable=0` it throws
  `RuntimeException`. `unregister()` and `reset()` clear the cache. All three are safe inside the matcher or
  the dispatcher (running calls keep their own reference to the callables).
- `OriginalCall` cannot be constructed (`new`, reflection, `unserialize`, `clone` all fail), has no properties,
  is callable (`$original($args)`, `Closure::fromCallable($original)`). Argument errors are the engine's
  (`Cannot use positional argument after named argument`, `Unknown named parameter $x`, `ArgumentCountError`).
  It does not reference the function: user and internal functions/methods live for the whole request.
- `setIntroductions()` and `fieldInterceptionAvailable()`: see "Engine notes (stream G)".
- Version-specific code (frame layout, frame teardown) is confined to `ext/aop_hook.c`. The generated
  `aop_arginfo.h` uses typed class constants (8.3+). It was generated from a copy of `aop.stub.php` without the
  generic `@param` docblocks, which the 8.3 `gen_stub.php` cannot parse.
- Build with `CFLAGS=-DAOP_DEBUG` for internal consistency checks that do not need a debug PHP.

Known limitations:

- **opcache inlining**: the optimizer (pass 16, part of the default `opcache.optimization_level`) inlines calls
  to functions/methods whose body is only `return <constant>;` (no typed or by-ref parameters). Those calls never
  reach the engine. Since stream H this is prevented by default (`aop.disable_inlining=1`, see "Compat notes
  (stream H)"); with `aop.disable_inlining=0` it applies (`ext/tests/200-opcache-inlining-allowed.phpt`).
- PHP disables the JIT while `zend_execute_ex` is overridden (it warns when the JIT is configured), and every
  user call recurses on the C stack, so very deep recursion hits the stack limit sooner.
- Advising internal functions that inspect their caller's frame (`compact`, `extract`, `get_defined_vars`,
  `func_get_args` called dynamically) breaks them: through `OriginalCall` their caller is `OriginalCall::__invoke`.
- The matcher guard is per request, not per fiber: suspending a fiber inside the matcher keeps interception
  suspended until the matcher returns.
- Observer end handlers are called for advised user frames (`zend_observer_fcall_end`), but this is not covered
  by the tests (no observer extension available in the test setup).

## Pointcut notes (stream E)

New designators of the expression language (`src/Pointcut/**`). All return `false` for kinds they cannot apply to and never throw.

### Grammar additions (EBNF)

```ebnf
designator      = ...existing...
                | "call" "(" methodPattern ")"
                | "withincode" "(" methodPattern ")"
                | "@withincode" "(" ( typePattern | binding ) ")"
                | "@this" "(" ( typePattern | binding ) ")"
                | "cflow" "(" pointcut ")"
                | "cflowbelow" "(" pointcut ")"
                | "if" "(" [ className "::" ] identifier "(" ")" ")"
                | "initialization" "(" ( ctorPattern | typePattern [ "+" ] ) ")"
                | "adviceexecution" "(" ")"
                | "handler" "(" typePattern [ "+" ] ")"
                | "get" "(" fieldPattern ")"
                | "set" "(" fieldPattern ")"
                | ( "preinitialization" | "staticinitialization" ) "(" ... ")"   (* UnsupportedPointcutDesignatorException *) ;
methodPattern   = [ "public" | "protected" ] returnType [ typePattern [ "+" ] "::" ] namePattern "(" paramList ")"
                | [ "public" | "protected" ] typePattern [ "+" ] "::__construct" "(" paramList ")" ;   (* constructor: no return type *)
ctorPattern     = [ "public" | "protected" ] typePattern [ "+" ] "::__construct" "(" paramList ")" ;
fieldPattern    = { fieldModifier } [ [ "?" ] typePattern [ "+" ] ] typePattern [ "+" ] "::" "$" namePattern ;
fieldModifier   = "public" | "protected" | "private" | "static" | "readonly" ;
```

`methodPattern` is the existing `execution()` pattern; a constructor pattern may omit the return type (also in `execution()`).
`$name` is a new token (`TokenType::Variable`), `*` is allowed in the name. `Lexer::DESIGNATORS` lists all designators, including the two unsupported ones.
AST: `CallNode`/`WithincodeNode` wrap an `ExecutionNode`; `AtThisNode`, `AtWithincodeNode`, `CflowNode(inner, below)`, `IfNode(?class, method)`,
`InitializationNode(modifier, declaringType, params)` (the shorthand `Foo+` is the same node with `(..)`), `AdviceExecutionNode`, `HandlerNode`,
`GetNode`/`SetNode` over `FieldPatternNode(modifiers, ?type, declaringType, namePattern)`.

### Designators

| Designator | Kinds it matches | Runtime | Caller-sensitive | Bindings |
|---|---|---|---|---|
| `execution(P)` | `method-`/`constructor-`/`function-execution` only | no | no | - |
| `call(P)` | `method-`/`constructor-`/`function-call` (P describes the callee) | no | no | - |
| `withincode(P)` | all; enclosing function = executing function for execution kinds, `initialization` and `advice-execution`, else `$caller->function` (false when unknown) | no | **yes** | - |
| `@withincode(A\|b)` | same enclosing function carries attribute A | only with binding | **yes** | `b` = attribute instance (`BindingKind::AT_WITHINCODE`) |
| `@this(A\|b)` | class of the executing (call kinds: calling) object carries A; false for static code, functions, unknown caller | only with binding | **yes** | `b` = attribute instance (`AT_THIS`) |
| `cflow(p)` / `cflowbelow(p)` | any kind (decided at runtime) | **always** | no (entries are matched with their own recorded caller) | the bindings of `p` |
| `if(C::m())` / `if(m())` | any kind | **always** | no | - |
| `initialization(P)` | `initialization`; `constructor-execution` if outermost | **always** | no | - |
| `adviceexecution()` | `advice-execution` | no | no | - |
| `handler(T)` | `exception-handler` with a caught type matching T (`T+`: or a subtype) | no | no | `args(e)` binds the exception |
| `get(F)` / `set(F)` | `field-get` / `field-set` (class prefilter accepts both field kinds) | no | no | `set`: `args(v)` is the new value |
| `service(n)` | any kind of a registered service | no | no | - |

`!` refuses bindings (`BindingException`), `||` branches must bind the same names, `&&`/`||`/`!` are runtime when a child is and caller-sensitive when a child is.

### API for the runtime (stream D)

* **cflow**: `AOP\Pointcut\ControlFlowPointcuts::of(PointcutInterface): list<PointcutInterface>` returns the inner pointcuts of every `cflow`/`cflowbelow`
  in a pointcut tree, nested ones included (empty for pointcuts that do not implement `ControlFlowAwareInterface::cflowPointcuts()`; And/Or/Not and
  `CflowPointcut` do). Give a join point the `TRACK` bit when `inner->matchesClass($class, $ctx) && inner->matchesMethod($function, $ctx)` for one of
  them (the static half; the runtime half is evaluated when the cflow is consulted). Keep tracked join points on `ControlFlowInterface::stack()` of the
  current Fiber while they are active, the **current join point last**. A runtime pointcut is evaluated by `matchesRuntime()` on a
  `RuntimeMatchContext` whose control flow is that stack; `cflowbelow` drops the last entry, so the last entry must be the context's own join point.
  Entries are matched by rebuilding their `StaticMatchContext` from `getSignature()`, `getTarget()`/`getThis()` and `getEnclosingStaticPart()`
  (`StaticContextFactory`), so those must be filled in (the enclosing static part gives `within`/`withincode`/`@this` of call, field and handler entries
  their caller). The innermost matching entry provides the bound values. `cflow` over an empty stack is false.
* **initialization / outermost**: for `constructor-execution` the pointcut is true only if `RuntimeMatchContext::isOutermost()`. That returns true unless
  the context's join point implements `AOP\Pointcut\OutermostAwareInterface { isOutermost(): bool }`. The runtime marks a `parent::__construct()` execution
  (descriptor `outermost => false`) by making its `JoinPointInterface` implement that interface and return false. `initialization` kinds are never filtered.
  The static half matches constructor executions of the *declaring* class (or the runtime class) of the constructor, so `Foo::__construct` run for a
  subclass object via `parent::` still matches statically and is rejected at runtime.
* **if()**: `PointcutExpressionCompiler::compile($expr, $names, $declaringAspect, $resolver)` resolves the short form `if(m())` against `$declaringAspect`
  (inside a named pointcut that is the class of the named pointcut, like `fromAspect`); an empty aspect or a bad method (missing, not static, return type
  other than `bool`, parameter other than one `JoinPointInterface`) throws `PointcutSyntaxException` at compile time. At runtime the static method is
  called with the current `JoinPointInterface` if it declares a parameter; a non-bool result throws `\UnexpectedValueException`. The pointcut is runtime
  with a static part of `true`, so wrap it in the `RuntimeGuardInterceptor` like every runtime pointcut.
* **get/set**: the engine's class probe (`kind => field-get, name => *`) is answered by `matchesClass()`, which accepts both field kinds, so `set()`-only
  aspects still get their classes wrapped. The full decision is `matchesMethod(null, $ctx)` with `$ctx->property` set.
* **handler**: `StaticMatchContext::$caughtType` must be set; `matchesClass()` passes a probe without it.
* **adviceexecution**: `StaticMatchContext::$function` is the advice method and `$targetClass` the aspect class, so `within(Aspect)` narrows it.

### Shared files touched by stream E

`src/Pointcut/**`, `tests/Unit/Pointcut/**`, `tests/Fixtures/Pointcut/**`, this section. `src/Binding/**` and `src/Exception/**` are untouched
(`BindingKind` gained `AT_THIS` and `AT_WITHINCODE`; `ParameterBinder` only reads binding names).
## Spring API integration (stream F)

Stream F adds the programmatic (Spring AOP API) layer on top of §4/§5. Everything it produces is an `AOP\Advisor\Advisor`
(programmatic: `$interceptor` set, `$advice` null) or, for configuration aspects, an `Advisor` with an `AdviceDefinition`
plus the aspect metadata/instances. Nothing in it needs the engine; the Weaver only has to register what it produces.

### 8.1 Public API (namespaces under `AOP\`)

- `Pointcut\Api`: `ClassFilterInterface::matches(\ReflectionClass)`, `MethodMatcherInterface` (`matches(fn, ?class)`, `isRuntime()`,
  `matchesRuntime(fn, ?class, array $args)`), `ApiPointcut(ClassFilter, MethodMatcher, ?list<JoinPointKind> $kinds)` (adapter to
  `PointcutInterface`; matches only method/function executions unless `$kinds` says otherwise), `StaticMethodMatcherPointcut` /
  `DynamicMethodMatcherPointcut` (abstract; implement `matches()` / `matchesArguments()`), `ComposablePointcut` (`union`/`intersection`),
  `Pointcuts` (`union`, `intersection`, `matches(pointcut, fn, ?class, args, ?target, controlFlow)`), `RegexpMethodPointcut`,
  `NameMatchMethodPointcut`, `AttributeMatchingPointcut`, `ControlFlowPointcut`, `ExpressionPointcut`, constants `TruePointcut::INSTANCE`,
  `TrueClassFilter::INSTANCE`, `TrueMethodMatcher::INSTANCE`.
- `Advice\Api`: `AdviceInterface`, `MethodInterceptorInterface::invoke(ProceedingJoinPointInterface)`, `MethodBeforeAdviceInterface::before(JoinPointInterface)`,
  `AfterReturningAdviceInterface::afterReturning(mixed, JoinPointInterface)`, `AfterAdviceInterface`, `ThrowsAdviceInterface` (marker; handlers are
  public methods named `afterThrowing*`, one exception type each, most specific type wins), `IntroductionInterceptorInterface`,
  `DelegatingIntroductionInterceptor(object $delegate, ?list<class-string> $interfaces)`; engine bridges in `Advice\Api\Interceptor\*`.
- `Advisor\Api`: `AdvisorAdapterInterface` (`supportsAdvice`, `getInterceptor`), `TypedAdvisorAdapterInterface` (+ `adviceType`), `ClosureAdvisorAdapter`,
  `AdvisorAdapterRegistry` (`registerAdvisorAdapter`, `supports`, `getInterceptor`, `adviceType`), `DefaultPointcutAdvisor(PointcutInterface|string, object $advice, int $order = PHP_INT_MAX, ?string $id = null, AdvisorAdapterRegistry = new)`,
  `RegexpMethodPointcutAdvisor`, `NameMatchMethodPointcutAdvisor`, `DefaultIntroductionAdvisor`. **Every advisor class has `toAdvisor(): Advisor`**; the Weaver consumes
  `$weaver->addAdvisor($api->toAdvisor())`. The advisor id is the caller's id or `<advice class>#<n>`.
- `TargetSource`: `TargetSourceInterface`, `SingletonTargetSource`, `HotSwappableTargetSource` (`swap()`), `PrototypeTargetSource`, `PoolingTargetSource` (`stats(): PoolStats`,
  `PoolExhaustedException`), `FiberLocalTargetSource`, `TargetSourceStandIn::create(class, source): array{0: object, 1: Advisor}`.
- `Config`: `ConfigLoader::load(array $config, ?callable $aspectFactory = null, ?AdvisorAdapterRegistry = null): ConfigResult` (array shape documented on the class),
  `ConfigResult::{metadata(): list<AspectMetadata>, aspectInstances(): array<class-string, object|\Closure>, aspectAdvisors(), programmaticAdvisors(), advisors(), namedPointcutResolver()}`,
  `ConfigurationException`.
- `Attribute\Configurable(autowire, preConstruction, service)`, `Configurable\ConfigurableAspect(callable(object, Configurable): void $configurer, int $order = PHP_INT_MIN)::toAdvisor(string $id = 'configurable')`.

### 8.2 What the Weaver must provide or call

1. **Programmatic advisors**: `addAdvisor(Advisor)` / `removeAdvisor(string $id)` as in §5. Advisors are built once and reused; `Advisor::$pointcut` may be runtime
   (`ControlFlowPointcut`, `DynamicMethodMatcherPointcut`): the Weaver builds chains with `AdvisorChainFactory::chainFor(..., $controlFlow)`, passing the runtime's `ControlFlowInterface`
   (`ControlFlowPointcut` reads `RuntimeMatchContext::$controlFlow->stack()`; with `EmptyControlFlow` it never matches).
2. **Per-instance advisors**: `Weaver::advised(object $instance): Runtime\AdvisedInstance` must offer `addAdvisor(Advisor)` (and `removeAdvisor(string $id)`), applying the advisor to
   method executions whose `JoinPointInterface::getThis()` is that very object. `TargetSourceStandIn` relies on it: its advisor's pointcut is runtime, matches exactly `$standIn`
   by identity (weakly held), and must be sorted last (order `PHP_INT_MAX`, id prefix `~~`, so every other advisor wraps it). The engine must dispatch method executions of the
   stand-in's class (the matcher answers `ADVISE` for that class, as for any class with advisors); the stand-in's own code never runs.
3. **Configuration aspects**: `Weaver::fromConfig(array $config, ?callable $aspectFactory = null)` (and `addConfig(ConfigResult)`) should call `ConfigLoader::load()` and then, for the result:
   - register each `AspectMetadata` of `metadata()` exactly as `addAspect()` does for attribute aspects (advisors via `AdvisorFactory::fromMetadata`, introductions via
     `AdvisorFactory::introductionsFromMetadata`, named pointcuts via a `MetadataNamedPointcutResolver` that includes `metadata()`), or simply `addAdvisor()` every `$result->advisors()`;
   - give the `AspectInstanceResolverInterface` the instances: `new ArrayAspectInstanceResolver($result->aspectInstances())` merged with the attribute aspects (closure entries = pertarget/perthis factories).
     `removeAspect(class)` must also drop these advisors (they carry `AdviceDefinition::$aspectClass`).
4. **`#[Configurable]`**: `$weaver->addAdvisor((new ConfigurableAspect($configurer))->toAdvisor())`. It matches `constructor-execution` (and `initialization`) of classes with the attribute
   (nearest attribute up the class hierarchy). Requirements on the runtime:
   - `constructor-execution` is dispatched for `parent::__construct()` too (§2 says so), each time with the same object as `getThis()`; the aspect counts running constructors per object to find the outermost one
     because **the join point model does not expose the descriptor's `outermost` flag** (gap: if `JoinPointInterface`/`JoinPointData` gains it, `ConfigurableInterceptor` could use it instead of the counter);
   - `initialization` join points (constructor-less classes) must pass the new object as `getThis()` (settled: §1.2 now passes the new object).
5. Introductions from `DefaultIntroductionAdvisor` run through the normal chain (delegating the introduced interface methods). For true interface introduction the Weaver still has to call
   `Engine::setIntroductions()` (§6) with `introducedInterfaces()`; F does not do that.

**Weaver additions (integration).** `Weaver::fromConfig(array, ?callable, ?EngineBridgeInterface)` / `addConfig(ConfigResult)` register the configured
aspects like `addAspect()` (aspect instances may be factories: `ScopedAspectResolver::register()` calls them lazily, once per scope key for
non-singletons) and add the programmatic advisors; `removeAspect()` drops them. `targetSource($class, $source)` creates a `TargetSourceStandIn` and
attaches its advisor with `advised()`. `configurable($configurer)` adds `ConfigurableAspect::toAdvisor()`. Chains are built with the runtime's
per-Fiber `ControlFlowInterface`. E2E notes: the scratch `aop.so` of stream A does not emit `initialization` yet, so that E2E test skips itself.

## Compat notes (stream H)

PHP 8.3, 8.4 and 8.5, NTS and ZTS, are supported by the same sources. Version differences are documented (and,
where code is needed, handled) in `ext/aop_compat.h`; version-specific tests are `ext/tests/2*.phpt` with a
`--SKIPIF--` on `PHP_VERSION_ID`. Checked against php-8.3.6, php-8.4.27 and php-8.5.12.

**Unchanged** (the frame code in `aop_hook.c` needs no guard): `zend_execute_data`, all `ZEND_CALL_*` flags and
frame macros, `i_init_func_execute_data()`/`zend_copy_extra_args()` (extra args behind CVs+TMPs), the
`ZEND_CALL_TOP` function branch of `zend_leave_helper` (8.5 only adds a TAILCALL-VM note), `ZEND_DO_FCALL` and
`zend_call_function()` around `zend_execute_ex()`, the `zend_execute_ex`/`zend_execute_internal` signatures,
`zend_fcall_info(_cache)`, `zend_call_known_fcc()`/`zend_call_known_function()`, and every `ZEND_ACC_*` flag the
engine reads or sets (8.4 renumbers `PROMOTED` and adds property flags, 8.5 adds `NODISCARD` and moves
`USE_GUARDS`; none is used). The 8.3-generated `aop_arginfo.h` compiles unchanged on 8.4/8.5 (8.4+ `gen_stub.php`
would use `zend_register_internal_class_with_flags()`; it needs network access to fetch PHP-Parser, so the file was
not regenerated).

**Differences and how they are handled**

| Version | Difference | Handling |
|---|---|---|
| 8.4 | `zend_observer_fcall_end()` became a static inline that checks `EG(current_observed_frame)` | none needed, same call |
| 8.4 | Frameless internal calls (`ZEND_FRAMELESS_ICALL_0..3`: `strpos`, `str_contains`, `in_array`, `implode`, `min`, `preg_match`, …) bypass `zend_execute_internal` | The compiler only emits them while `zend_execute_internal` is NULL (`find_frameless_function_info()`, 8.4 and 8.5), and the hook is installed in MINIT, so they are never emitted while `aop.enable=1`: those functions are intercepted normally (`206-frameless.phpt`). No user opcode handler is needed. Remaining gap: scripts compiled by a process **without** the hook and loaded from a shared `opcache.file_cache` keep their frameless opcodes. |
| 8.4 | Property hooks are user functions (`common.prop_info` set, named `$prop::get`/`$prop::set`); the "simple get" VM fast path enters them without `zend_execute_ex` | The fast path is only cached while `zend_execute_ex == execute_ex`, so with the hook every hook call reaches the engine. Hook bodies pass through (`aop_is_property_hook()`): they are not methods (no `ReflectionMethod` by that name) and their accesses are field join points (§6). Calls made *from* a hook report `caller.function` = `$prop::get` with `scope` the declaring class; the PHP side resolves it with `ReflectionProperty::getHook()` (`204-property-hooks.phpt`). |
| 8.4 | Lazy objects | The engine never reads properties of `$this`, so advising a method of an uninitialized ghost/proxy does not initialize it; the original initializes it on its first property access, as without advice (`205-lazy-objects.phpt`). `class`/`$this` of a proxy are the proxy. |
| 8.4 | `exit()`/`die()` is an internal function | Advisable like any internal function; proceeding exits (finally blocks of the dispatcher do not run, shutdown functions do) (`207-exit-function.phpt`). |
| 8.4 | `#[\Deprecated]` user functions (`ZEND_ACC_DEPRECATED`) | The call site reports the deprecation, and `zend_call_function()` reports it again for every `OriginalCall` (`208-deprecated-attribute.phpt`). Same for deprecated internal functions on 8.3. Not suppressed: that would need writing to (possibly shared, read-only) op_arrays. |
| 8.4 | Closure names in traces are `{closure:file:line}` | Tests match `{closure%S}`. |
| 8.5 | Pipe operator `\|>` | Compiled to ordinary calls; advised normally (`209-pipe-nodiscard.phpt`). |
| 8.5 | `#[\NoDiscard]` (`ZEND_ACC_NODISCARD`) | Checked once at the call site; `zend_call_function()` does not check it, so `OriginalCall` does not repeat the warning. |
| 8.5 | opcache is always built in (`extension_loaded('Zend OPcache')`, no `zend_extension=opcache`) | Tests check `extension_loaded('Zend OPcache')` and enable it with `opcache.enable_cli=1` in `--INI--`. |

**opcache inlining** (`aop.disable_inlining`, `PHP_INI_SYSTEM`, default `1`; only with `aop.enable=1`). In MINIT
the extension also registers itself as a zend_extension whose `op_array_handler` (run by `pass_two()` for every
op_array compiled with `ZEND_COMPILE_HANDLE_OP_ARRAY`, which is the default and which opcache sets, before
opcache's optimizer runs) adds `ZEND_ACC_HAS_TYPE_HINTS` to every named function and method (not closures, not
property hooks). `zend_try_inline_call()` (pass 16, identical in 8.3–8.5) never inlines such callees. On an untyped
function the flag only makes the VM execute its `RECV` opcodes, which accept untyped parameters, exactly as for any
function with one typed and one untyped parameter (`201-opcache-inlining-shapes.phpt` covers by-ref, defaults,
variadics, named extras and the missing-argument error). Cost: one `RECV` per declared parameter per call.
The zend_extension has no `startup`, so `php -v` does not list it. Alternatives rejected: changing
`opcache.optimization_level` from MINIT (only works when loaded before opcache, and turns off the pass for every
function), `ZEND_COMPILE_EXTENDED_STMT` (EXT_STMT before every statement: slower and visible to debuggers),
`ZEND_ACC_DEPRECATED` (changes behaviour). What remains: scripts cached by a process without aop (shared
`opcache.file_cache`, a different `aop.disable_inlining`) keep their inlined calls; SCCP still evaluates calls to
some internal functions with constant arguments at compile time, and internal functions compiled to dedicated
opcodes (`strlen`, `count`, …) are unchanged (§1.4). Setting `ZEND_COMPILE_NO_BUILTINS` in RINIT would remove the
latter at a small cost per builtin; not done.

**JIT**. On every version `zend_jit_check_support()` turns the JIT off when `zend_execute_ex != execute_ex`, which
is checked in opcache's post-startup, after our MINIT. When the JIT is configured for the current SAPI (opcache
enabled, `opcache.jit` not `disable`, `opcache.jit_buffer_size` > 0), PHP warns ("JIT is incompatible with third
party extensions that override zend_execute_ex(). JIT disabled.") and aop adds the startup notice
`aop: the opcache JIT is disabled because aop interception overrides zend_execute_ex(); set aop.enable=0 to use the JIT`
(from a `zend_post_startup_cb`, so opcache's INI is registered). `phpinfo()` shows the state in the rows
"opcache JIT" and "opcache function inlining". With `aop.enable=0` no hook, no zend_extension and no callback is
installed and the JIT runs (`202-jit-disabled.phpt`, `203-jit-aop-disabled.phpt`; 203 skips under valgrind
because the JIT's IR compiler itself reports uninitialised reads).

**Testing notes**. `make test` runs with `-n`, so on 8.3/8.4 opcache is not loaded and the opcache/JIT tests
(025, 200–203) skip; CI should run the phpt suite a second time with
`TEST_PHP_ARGS="-q -d zend_extension=$(php -r 'echo ini_get("extension_dir");')/opcache.so -d opcache.enable_cli=1"`
(8.5: only `-d opcache.enable_cli=1`, opcache is built in).

## Engine notes (stream G)

The experimental join points of §6 as implemented by `ext/` for PHP 8.3 (`ext/aop_fields.c`, `ext/aop_catch.c`,
`ext/aop_ast.c`; tests: `ext/tests/1*.phpt`). They use the decision protocol of §1.1 and the dispatcher call of §1.2
(`aop_decide_jp()` / `aop_dispatch_jp()` in `ext/aop_engine.c`), with their own decision tables, which `reset()` and
`unregister()` clear like the others. Nothing here is active while no pair is registered or while the matcher runs,
and code in `AOP\` (the enclosing frame, or for internal frames the nearest user frame below) never produces these
join points. A matcher exception is the usual warning, worded `... for field-get Class::$prop; the join point is not
intercepted` (`exception-handler catch(Class)`, `initialization Class`). The `ZEND_NEW` and `ZEND_CATCH` user opcode
handlers chain to handlers installed before them; both are installed only with `aop.enable=1`.
`fieldInterceptionAvailable()` returns true when the hooks are installed.

### Fields (`field-get`, `field-set`)

- **Which objects.** On `new C` (`ZEND_NEW`) the class probe `{kind: field-get, class: C, scope: null, name: '*',
  static: false, internal, caller: null}` is asked once per class (cached; any non-zero answer counts; the
  `CALLER_SENSITIVE` bit is ignored and never asked per caller). Then the object gets a per-class copy of the
  standard handlers with `read_property`, `write_property`, `get_property_ptr_ptr` and `clone_obj` wrapped (freed in
  post-deactivate, after the last object). Only classes whose objects use the standard handlers are probed: user
  classes and subclasses of internal classes that keep them (`stdClass`); never `Throwable`s, `Closure`,
  `ArrayObject`, `DateTime` and other internal classes with handlers of their own, nor their subclasses.
  **Not covered**: objects created before `register()` or while the probe answered 0 (after `reset()` only new
  objects are probed again), created without `new` (`unserialize`, `newInstanceWithoutConstructor`, `PDO`
  fetch-object, internal code), by `new` in `AOP\` code or in the matcher. Clones of wrapped objects are wrapped
  before `__clone()` runs (its accesses are covered); clones of other objects are not. A wrapped object stays
  wrapped after `reset()`/`unregister()`; each access asks again or passes through.
- **Descriptor.** `class`: runtime class; `scope`: declaring class of the property as resolved from the accessing
  scope (a parent's private property when accessed from the parent), null for undeclared names (dynamic
  properties, names served by `__get`/`__set`); `name`: property name; `static`: false; `internal`: the declaring
  class is internal; `caller`: the user frame containing the access (`line` = line of the access), null when an
  internal function accesses the property (`ReflectionProperty::getValue()`, ...). Decisions are
  cached per (runtime class, property info, name, kind), then per caller as in §1.1. `field-set`: `value` and
  `$args[0]` are the raw value (no coercion yet); `field-get`: `$args` is `[]`. `$this` is the object.
- **Accesses and their join points** (VM handlers verified in 8.3.6):

  | Access | Join points |
  |---|---|
  | `$o->p`, `$o?->p`, `$o->p ?? x`, by-value argument | `field-get`; the dispatcher's result is the value |
  | `$o->p = v`, `[$o->a, $o->b] = ...` | `field-set`; the dispatcher's result is ignored; the expression's value is the assigned value |
  | `$o->p += v` (all compound operators), `$o->p++`, `--$o->p` | `field-get` then `field-set` with the new value (`get_property_ptr_ptr` returns NULL, so the VM reads and writes through the handlers) |
  | write fetches: `$o->p[] = v`, `$o->p['k'] .= v`, `$o->p->q = v`, `$r = &$o->p`, `$o->p = &$v`, `foreach ($o->p as &$v)`, by-reference argument | `field-get` only, as a notification: the dispatcher's result is ignored (its `OriginalCall` reads in `isset` mode, no warning) and the access then works on the property itself |
  | `isset`/`empty`/`unset($o->p)`, `property_exists()`, static properties, `get_object_vars()`, `foreach ($o as ...)`, `(array)`, `var_dump()`, `serialize()`, `json_encode()` | none (other handlers) |

- **Runtime cache.** The VM's property fast paths (`ZEND_FETCH_OBJ_*`, `ZEND_ASSIGN_OBJ`, `zend_fetch_property_address()`)
  skip the handlers only when slot 0 of the opline's cache equals the object's class. The wrappers run the original
  handlers with a private cache and then store only slot 2 (the typed property info, which the VM reads after
  `get_property_ptr_ptr`/`read_property` for typed references and auto-vivification checks) and clear slots 0 and 1,
  so no opline is ever primed for a wrapped object. Consequences: every access to a wrapped object goes through the
  handlers. **Known limitation**: an opline whose cache an uncovered object of the same class primed (e.g. a getter
  first called on an object created before `register()`) keeps bypassing the handlers for that class until an
  object of another class passes it (`ext/tests/105-fields-coverage.phpt`).
- **Visibility, readonly, types.** Accessibility is checked first (`zend_get_property_info()` from the accessing
  scope): an inaccessible declared property is no join point, the original handler raises the error or calls
  `__get`/`__set`. Readonly: initialization in the declaring scope and re-initialization in `__clone()` are
  `field-set` join points; a write that fails anyway (initialized, or from another scope) and a write fetch of a
  readonly property that fails are no join points. Typed properties: the original write coerces or throws the
  `TypeError` inside `OriginalCall`, with the typing mode of the file containing the access (`strict_types=1` is
  checked explicitly, since the write itself runs from an internal frame). Reading an uninitialized typed property
  throws inside `OriginalCall`.
- **Magic.** Undeclared names are join points; `__get`/`__set` run inside the original access, and accesses they
  make are join points themselves (PHP's recursion guard applies as usual).
- **OriginalCall.** `field-get`: `__invoke([])` (arguments ignored) reads with the accessing scope
  (`EG(fake_scope)`) and mode, returns a copy of the value. `field-set`: `__invoke([$value])` writes with the
  accessing scope and returns null; `ArgumentCountError` without a value. It references the object and may be
  called later or repeatedly. The dispatcher's exceptions propagate from the access; a set that never proceeds
  writes nothing.

### `initialization`

- Emitted by the `ZEND_NEW` handler for classes without a constructor (`ce->constructor == NULL`; classes with an
  inherited constructor get `constructor-execution`), right after the object is created (and wrapped), before the
  arguments of `new C(...)` are evaluated. Not for abstract classes, interfaces, enums (the original raises the
  error), `AOP\` classes, `new` in `AOP\` code.
- Descriptor: `class`/`scope` = C, `name` `__construct`, `static` false, `internal` = C is internal (`new stdClass`
  is a join point), `caller` = the frame executing `new`. `$this` = the new object, `$args` = `[]`, `OriginalCall`
  a no-op returning null, the result is ignored. A dispatcher exception aborts `new`: the object is released
  without calling its destructor (like a failed constructor) and the exception propagates.

### `exception-handler`

- Emitted by the `ZEND_CATCH` handler for the catch clause that catches the in-flight exception (the original
  matching is replicated: exact class or `instanceof`; a clause whose class is not loaded never matches). A
  multi-catch `catch (A | B $e)` is one `ZEND_CATCH` per class, so `name` is the class of the matching
  alternative. `try`/`finally` without a matching catch is no join point; `exit()` is never one.
- Descriptor: `class` = runtime class of the enclosing code (null in functions and top-level code), `scope` =
  its declaring class, `name` = caught class (as declared), `static` = enclosing method is static, `internal`
  false, `caller` = the enclosing frame (`line` = line of the `catch`), `exception` = the exception. `$this` = the
  enclosing `$this`, `$args` = `[$exception]`. Decisions are cached per (enclosing code, runtime class, catch
  class), then per caller. The dispatcher runs with no exception pending.
- Before-only: return value ignored, `OriginalCall` a no-op. **An exception from the dispatcher** (or from an error
  handler turning the matcher warning into one) **replaces the caught exception** (AspectJ: an exception thrown by
  before advice at a handler join point propagates from it): it is raised at the `catch` clause, so no clause of
  the same `try` catches it, that `try`'s `finally` runs, and outer `try` blocks may catch it. The caught exception
  is released, not attached as `previous`.

### Introductions (`Engine::setIntroductions()`)

- **Decision: compile-time AST weaving** through a `zend_ast_process` hook (chained), not link-time weaving: the
  result is ordinary compiled code, identical with and without opcache, and the methods get real signatures.
  `setIntroductions()` validates every rule (`ValueError` for missing/empty keys and for an `interface` that is not
  an interface; the interface is loaded, autoloading allowed; `defaultImpl` is loaded lazily on first use) and
  renders each interface method once. It replaces the previous rules; `[]` stops weaving. Only files (and `eval`
  code) compiled afterwards are woven. With `aop.enable=0` the rules are validated but nothing is woven.
- **Classes**: every class declaration (top level, conditional, inside function bodies; not interfaces, traits,
  enums, anonymous classes) whose fully qualified name matches a `typePattern`: the syntax of
  `AOP\Pointcut\Pattern\TypePattern` (`*` within one segment, `*` / `**` as whole segments; case-sensitive; a
  leading `\` is ignored; a trailing `+` is ignored: subtypes are unknown at compile time).
- **implements**: `\Interface` is appended unless the implements list already names it (resolved through the file's
  namespace and `use` imports) or an earlier rule added it.
- **Methods**: for every interface method (also inherited from parent interfaces; except `__construct`) that the
  class body does not declare, a public method with the interface's signature is added (parameter and return
  types fully qualified, `self` → the interface; defaults copied from the interface: literals via `var_export`,
  constant expressions re-exported with fully qualified names, an unqualified namespaced constant resolved when
  `setIntroductions()` runs; by-reference and variadic parameters; internal interfaces use the arginfo default text).
  The body forwards every parameter to `\AOP\Internal\introductionDelegate($this, 'Impl')->m(...)` (`void`: no
  return value; static methods: `\Impl::m(...)`). If the class has a parent, it first checks
  `\AOP\Internal\introductionInherited(__CLASS__, 'm')` and calls `parent::m(...)` when the parent has a concrete
  `m()`, so inherited implementations win (the parent is unknown at compile time). Generated nodes carry the line of
  the class declaration. They are ordinary methods (`method-execution` join points).
- **Delegates**: one `defaultImpl` instance per (object, implementation class), created on first use with no
  constructor arguments (a non-public constructor is an `Error`), held with weak keys: released with the object. A
  delegate that references its object keeps both alive until the end of the request. Clones get a fresh delegate.
  Several rules with the same `defaultImpl` share the instance.
- **Internal helpers** (registered by the extension, in `AOP\` so never intercepted, not in `aop.stub.php`):
  `AOP\Internal\introductionDelegate(object $self, string $impl): object`,
  `AOP\Internal\introductionInherited(string $class, string $method): bool`.
- **Opcache**: nothing is looked up while a file compiles (opcache swaps `EG(class_table)` during compilation, and
  autoloading there is unsafe); all inputs come from `setIntroductions()`. The woven file is cached like any other:
  later requests use it even with different or no rules, so changing the rules needs an opcache reset
  (`ext/tests/122-opcache.phpt`).
- **Limitations**: a method a class gets from a trait is shadowed by the delegate (traits are unknown at compile
  time); a `static` return type fails unless the delegate's method returns the object (it returns the delegate);
  a `final` parent method of the same name is a fatal inheritance error; a woven class implements an interface, so
  it is no longer early-bound (it cannot be used before its declaration in the same file); parameter attributes are
  not copied; incompatible existing methods give the usual inheritance errors.

### Shared code (for streams touching `ext/aop.c`, `ext/aop_engine.c`, `ext/php_aop.h`)

- `aop_engine.c`: the matcher call was split into `aop_invoke_matcher()` (call, guard) and the reporters
  `aop_report_matcher_exception_ex()` / `aop_report_matcher_exception()` (stream A's message is unchanged); the
  dispatcher call into `aop_call_dispatcher()`; `OriginalCall` gained a native mode
  (`aop_original_call_new_native()`); new block "stream G: generic join points" (`aop_decide_jp()`,
  `aop_dispatch_jp()`, `aop_current_code_in_aop_ns()`); `jp_decisions`/`jp_caller_decisions` in rinit/reset/rshutdown.
- `php_aop.h`: globals block "stream G", `aop_jp`, `aop_original_native_fn` and the prototypes above.
- `aop.c`: MINIT/MSHUTDOWN/RINIT/RSHUTDOWN calls into `aop_g.h`, a post-deactivate function, `setIntroductions()`
  and `fieldInterceptionAvailable()`, the phpinfo row.
