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

## 8. Pointcut notes (stream E)

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
