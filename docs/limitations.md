# Limitations

This page lists what the engine cannot intercept, what is still experimental, and the known issues.
Read it before you rely on a pattern in production.

The statements here describe this build.
Each one was checked by running code, unless the text says otherwise.

## Engine limits

These are properties of PHP and of the hook design, not bugs.

### JIT

The JIT is disabled while the extension is loaded.
PHP warns about it when the JIT is configured:

```text
PHP Warning:  JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled.
```

The extension overrides `zend_execute_ex()`, which the JIT does not allow.
Turn the JIT off in production if the extension is loaded, to avoid the warning.

### Opcache inlining

Opcache can inline a call to a function whose body is only `return <constant>;`.
The call then never reaches the engine, so no advice runs.

Here `constantBody()` returns a string literal, and the call is inlined at compile time.
The extension's test `ext/tests/025-opcache-inlining.phpt` shows it, and the `constantBody()` call never reaches the engine.


The extension's test `ext/tests/025-opcache-inlining.phpt` shows the behaviour.
Set `opcache.optimization_level=0x7FFE3FFF` to keep these calls.
With that level, both calls reached the engine when checked.

### Compiler-specialised internal functions

Some internal functions compile to dedicated opcodes.
`strlen`, `count` and `is_*` are examples.
An unqualified call to such a function in the global namespace uses the opcode, so no advice runs.

In a namespaced file, advice on `strlen()` runs and prints `hit strlen(..)` before the call's result.
In the global namespace, `strlen('abc')` and `\strlen('abc')` compile to the opcode, and the same advice never runs.
Do not rely on advice for these functions.
The engine notes say that PHP 8.4 frameless calls are not intercepted either, but this build was only run on PHP 8.3.

### Frame-inspecting internal functions

Some internal functions read the frame of their caller.
`compact()`, `extract()`, `get_defined_vars()` and `func_get_args()` called dynamically are examples.
Through the engine, the caller is `OriginalCall`, not your function.
So advising them breaks them.
Advising `compact()` made the call fail:

```text
Error: Cannot call compact() dynamically
```

### By-reference returns

A function or method that returns by reference is never advised.
The engine leaves those calls alone.

### Closures

Real closures and arrow functions are never advised.
Calls to them run as ordinary PHP.
First-class callables of methods and functions, such as `$object->method(...)`, are advised as the method they wrap.

### Generators

A generator function is a join point when it is called.
Resuming the generator is not a join point.
Advice on a generator runs once, when the generator is created.

### The `AOP\` namespace

The extension never weaves classes in the `AOP\` namespace.
Calls made from code in `AOP\` are not intercepted either.
This keeps the library itself out of the weave.
Keep your own code out of `AOP\`.

### Fibers and the matcher

Control flow (`cflow()`) is tracked per Fiber.
The matcher runs with interception suspended.
A Fiber suspended inside the matcher keeps interception suspended until the matcher returns.

### Stack depth

Every user call recurses on the C stack.
Deep recursion reaches the stack limit sooner than without the extension.

### Supported PHP versions

The package supports PHP 8.3, 8.4 and 8.5, the versions in the release matrix.
[building.md](building.md) describes the Docker matrix that builds and tests them.
Only PHP 8.3 NTS was built and tested in the development environment for this release.

## Not applicable in PHP

| Feature | Why |
|---|---|
| `preinitialization()`, `staticinitialization()` | PHP has no such moments. Constructor arguments are evaluated before the constructor, and there are no static initializers that run in a fixed order. The compiler rejects both with `UnsupportedPointcutDesignatorException`. |
| Proxy-based restrictions | None. There are no proxies, so `final` classes, private methods and self-invocation are advised. |

## Experimental features

These features are part of the design, but this build does not provide them yet.
Their designators and classes exist, and some of them compile.
They have no runtime effect in this build.

| Feature | Designator or API | Status in this build |
|---|---|---|
| Field read and write | `get()`, `set()`, `field-get`, `field-set` | Compiles. Advice does not run. `AOP\Engine::fieldInterceptionAvailable()` returns `false`. |
| Exception handlers | `handler()`, `exception-handler` | Compiles. Advice does not run. |
| Constructor-less initialization | `initialization()` for a class without a constructor, `#[Configurable]` on such a class | Not emitted. The class is not configured. |
| Introductions | `#[DeclareParents]`, `declare-parents`, `DefaultIntroductionAdvisor` | Accepted. `instanceof` the introduced interface stays `false`. |

Use these designators only when you can test the result in your own environment.
The rest of the documentation does not depend on them.

## Known issues

These are defects or deviations from AspectJ and Spring that were found while writing these pages.
Each one lists how it shows up, and a workaround where there is one.

### Class loading is not woven

Calls made while PHP autoloads a class are not intercepted, so a broad pattern such as `execution(* *(..))` does not advise the autoloader or the internal functions it calls.
The runtime loads its own classes lazily during dispatch; weaving the autoloader would re-enter the dispatcher.
Set `aop.weave_autoload=1` (any INI level) to weave class loading anyway, with an aspect that cannot recurse into itself.

Runaway recursion through advised code raises PHP's `Maximum call stack size` `Error` (PHP 8.3+ stack limit) instead of crashing.

### `execution()` matches constructors of subclasses

`execution(* Base::__construct(..))` also matches the constructor of a subclass, and the `parent::__construct()` call inside it.
So the advice can run several times for one `new`.

Use `initialization(Base+)` to get one advice call per object, for the outermost constructor.
[`examples/04-constructors.php`](../examples/04-constructors.php) shows both.

### Type patterns match overrides through the base type

`execution(* Base::run(..))` also matches `run()` declared in a subclass that overrides it.
AspectJ matches only the declared method.
Write the subclass name when you want only that class.

### Method modifiers are limited

Only `public` and `protected` are accepted as modifiers in a method pattern.
`private`, `static` and `final` raise a syntax error, and the message does not say why:

```text
Unexpected "Demo\Mods\Svc", expected "(" at position 20 in pointcut "execution(private * Demo\Mods\Svc::priv(..))".
```

To select private, static or final methods, leave out the modifier and match by name or type.

### Spring `ControlFlowPointcut` differs from `cflow()`

The Spring form matches call join points as well as executions.
So advice runs at the call site and again inside the method.
It also does not register its entry join point with the control-flow stack.
So it only works when other advice advises the entry point.
Use the `cflow()` designator instead.
[`examples/07-cflow.php`](../examples/07-cflow.php) shows it working on its own.

### `RegexpMethodPointcut` takes whole-name patterns

Patterns match the whole `Class::method` name, and they take no delimiters.
A pattern with delimiters, such as `/Catalog::save/`, does not match and raises no error.
Write `.*Catalog::save` instead.

### Compile-time specialisation changes behaviour by namespace

The same call is intercepted in one file and not in another, depending on whether it is namespaced (see [compiler-specialised internal functions](#compiler-specialised-internal-functions)).
Do not rely on advice for internal functions in the global namespace.

## Reporting

If you find a case that is not listed here, open an issue with the smallest script that shows it.
The issue should name the PHP version, the extension version and the output of `php -i`.
