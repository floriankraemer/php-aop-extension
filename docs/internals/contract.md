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

final readonly class SourceLocation { public ?string $file; public ?int $line; public ?string $class; public ?string $function; }
final readonly class StaticPart { public JoinPointKind $kind; public SignatureInterface $signature; public ?SourceLocation $sourceLocation; }

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
where `JoinPointData` is a readonly value object `{kind, this, target, signature, staticPart, enclosingStaticPart, sourceLocation}`.
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
        public readonly ?EnclosingContext $caller = null,               // {?ReflectionClass $class, ?ReflectionFunctionAbstract $function, ?string $file}
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
    public function isCallerSensitive(): bool;   // static result depends on $ctx->caller (call, withincode, within on call kinds, ...)
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
    /** Active join points of the current Fiber, outermost first, the current one last. */
    public function stack(): array;
}
```

Designator semantics per kind follow AspectJ: `execution` → *-execution kinds; `call` → *-call kinds; `within`/`withincode`
look at the executing code for execution kinds and at the caller for call kinds; `this` is the executing (call kinds:
calling) object, `target` the callee object; `args` is the argument list (field-set: the value; handler: the exception).
A designator that cannot apply to a kind returns false (never throws).

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
`AdvisorChainFactory::chainFor()` uses `$interceptor` as-is when present (still wrapped in `RuntimeGuardInterceptor`
when the pointcut is runtime). `AdvisorOrderComparator` sorts by order, then source (aspect class or id), then type
precedence, then declaration index.

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
- `setIntroductions()` only stores a copy of the rules; `fieldInterceptionAvailable()` returns false (stream G).
- Version-specific code (frame layout, frame teardown) is confined to `ext/aop_hook.c`. The generated
  `aop_arginfo.h` uses typed class constants (8.3+). It was generated from a copy of `aop.stub.php` without the
  generic `@param` docblocks, which the 8.3 `gen_stub.php` cannot parse.
- Build with `CFLAGS=-DAOP_DEBUG` for internal consistency checks that do not need a debug PHP.

Known limitations:

- **opcache inlining**: the optimizer (pass 16, part of the default `opcache.optimization_level`) inlines calls
  to functions/methods whose body is only `return <constant>;` (no typed or by-ref parameters). Those calls never
  reach the engine. Use `opcache.optimization_level=0x7FFE3FFF` to keep them (`ext/tests/025-opcache-inlining.phpt`).
- PHP disables the JIT while `zend_execute_ex` is overridden (it warns when the JIT is configured), and every
  user call recurses on the C stack, so very deep recursion hits the stack limit sooner.
- Advising internal functions that inspect their caller's frame (`compact`, `extract`, `get_defined_vars`,
  `func_get_args` called dynamically) breaks them: through `OriginalCall` their caller is `OriginalCall::__invoke`.
- The matcher guard is per request, not per fiber: suspending a fiber inside the matcher keeps interception
  suspended until the matcher returns.
- Observer end handlers are called for advised user frames (`zend_observer_fcall_end`), but this is not covered
  by the tests (no observer extension available in the test setup).
