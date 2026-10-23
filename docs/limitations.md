# Limitations

This page lists what the engine cannot intercept, the limits of the fields, handler, initialization and introduction join points, and the known issues.
Read it before you rely on a pattern in production.

The statements here describe this build.
Each one was checked by running code, unless the text says otherwise.

## Engine limits

These are properties of PHP and of the hook design, not bugs.

### Backends

The setting `aop.interception` (`woven`, `hook` or `auto`, php.ini only) selects how calls reach the engine.
The choice is made once at startup, before opcache decides about the JIT.

| | `woven` (default) | `hook` |
|---|---|---|
| How | A prologue is compiled into every named function and method. Internal functions use `zend_execute_internal`. | `zend_execute_ex()` is overridden, and user opcode handlers for `ZEND_NEW` and `ZEND_CATCH` are installed. |
| Opcache JIT | Stays on. | Disabled by opcache. |
| `execution()`, `call()`, `within()`, `cflow()` and the other call-based pointcuts | Supported. | Supported. |
| `handler()` | Supported (a call woven into each `catch` block). | Supported. |
| `get()`, `set()` | Not available. | Available. |
| `initialization()` | Not available, for any class (see below). | Available. |
| Introductions | Supported. | Supported. |
| Advice arguments | After parameter coercion, see [woven differences](#woven-differences). | As passed. |
| Advice on a generator function | At the first resume. | At the call that creates the generator. |

`auto` picks `hook` unless the JIT is configured for the SAPI (opcache enabled, `opcache.jit` not `disable`, `opcache.jit_buffer_size` > 0), then it picks `woven`.
`opcache.jit=off` counts as configured, because the JIT can be switched on at run time.
`aop.enable=0` installs neither backend, and the JIT runs.
`phpinfo()` shows the result in the row "Interception backend".

The hook backend disables the JIT.
PHP warns about it when the JIT is configured, and the extension adds a notice:

```text
Notice: aop: the opcache JIT is disabled because aop.interception=hook overrides zend_execute_ex(); set aop.interception=woven to keep the JIT
Warning: JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled.
```

Under `woven`, `Engine::fieldInterceptionAvailable()` returns `false`, and `Weaver::enable()` throws an `AspectDefinitionException` for an advisor that can match `get()`, `set()` or `initialization()`.
Every `initialization(...)` pointcut is rejected, also one for a class with a constructor, because `enable()` cannot tell classes with and without a constructor apart.
For a class with a constructor, use `execution(* App\Connection::__construct(..))`.
The exception names the first such advisor, and `enable()` enables nothing:

```text
AOP\Exception\AspectDefinitionException: Advisor "A::b" targets field-get, field-set or initialization join points, which the active interception backend cannot intercept. Set aop.interception=hook (or aop.interception=auto without the opcache JIT) in php.ini.
```

The JIT cannot honour the per-object property handlers that field interception swaps in, which is why fields cannot work under it.
If you need these pointcuts, set `aop.interception=hook` and run without the JIT, or `auto` if the JIT is off in that environment.

### Woven differences

These apply to `aop.interception=woven` only.
Each one was checked by running code, unless the text says otherwise.

- Advice sees the arguments after the parameter list ran.
  Values are coerced to the declared types (`'5'` for an `int` parameter arrives as `5`), and a `TypeError` for a bad argument is raised before the advice runs.
  Omitted trailing parameters are still absent from the argument array, as under `hook`.
- Advice on a generator function runs at the first resume, not when the function is called.
  Proceeding does not run the advice a second time.
- Exception backtraces and `debug_backtrace()` contain a frame `AOP\Internal\intercept()` (or `AOP\Internal\caught()` for `handler()` advice) between the advised function and the dispatcher.
- A constructor with promoted properties: the properties are reset to their defaults before the dispatcher runs, so that proceeding can run the promotion again.
  This also works for `readonly` properties.
- Default values that are expressions with side effects (`new Foo` as a parameter default) run twice when advice proceeds: once when the function receives its parameters, and again when `proceed()` calls the function.
  Under `hook` they run once.
- When `handler()` advice throws, the caught exception stays alive until the replacing exception is freed, because it appears in that exception's trace arguments (`AOP\Internal\caught(Object(RuntimeException), Array)`).
- `aop.disable_inlining` has no effect: the prologue makes a function body more than `return <constant>;`, so opcache does not inline it.
- Files that a process in another mode compiled into a shared `opcache.file_cache` keep that process's weaving.
  Clear the file cache after you change `aop.interception`.
  No test covers this one.

### Opcache inlining

Opcache can inline a call to a function whose body is only `return <constant>;`.
The call then never reaches the engine, so no advice runs.

The extension prevents this by default.
The setting `aop.disable_inlining` (default `1`, see [building.md](building.md#configuration-phpini)) keeps opcache from inlining calls to user functions and methods, so advice runs for them.
With `aop.disable_inlining=0`, opcache may inline such a call at compile time, and the advice does not run.
The extension's tests show both settings: `ext/tests/025-opcache-inlining.phpt` (default: the call is advised) and `ext/tests/200-opcache-inlining-allowed.phpt` (allowed: the call is inlined).

Two cases remain with the default setting:

- Scripts compiled by a process without the extension, and cached in a shared `opcache.file_cache`, keep their inlined calls.
- Internal functions compiled to dedicated opcodes are not affected by the setting (see the next section).

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
Under `hook`, advice on a generator runs once, when the generator is created.
Under `woven`, it runs once, at the first resume (see [woven differences](#woven-differences)).

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
The phpt suite passes, plain and with opcache, on PHP 8.3.6, 8.4.27 and 8.5.12, NTS and ZTS (glibc, x86_64), with no compiler warnings. Valgrind is clean for the full suite on 8.3 and for the method/function engine on 8.4 and 8.5.
The phpunit suites and the examples ran on 8.3; musl, arm64, macOS and Windows are built and tested by the release workflow.

## Not applicable in PHP

| Feature | Why |
|---|---|
| `preinitialization()`, `staticinitialization()` | PHP has no such moments. Constructor arguments are evaluated before the constructor, and there are no static initializers that run in a fixed order. The compiler rejects both with `UnsupportedPointcutDesignatorException`. |
| Proxy-based restrictions | None. There are no proxies, so `final` classes, private methods and self-invocation are advised. |

## Fields, handlers, initialization and introductions

These join points are implemented in the engine.
They work on PHP 8.3, 8.4 and 8.5.
Fields and `initialization` need `aop.interception=hook` (see [backends](#backends)); exception handlers and introductions work with both backends.
On PHP 8.4+:

- An access to a hooked property from outside its own hooks is one `get`/`set` join point around the whole hooked access; the hook body runs inside `proceed()`, so advice that does not proceed skips the hook.
  Accesses from inside the property's own hooks (the backing value) are not join points.
- Field advice does not initialise a lazy object by itself; proceeding does, as without advice.
  Lazy objects created with `ReflectionClass::newLazyGhost()`/`newLazyProxy()` are not covered (they are not created with `new`), and accesses a proxy forwards to its real instance show up once, on the proxy.
- Write fetches (`$o->p[] = 1`, `&$o->p`) on `&get` hooks are not join points.

### Fields

Hook backend only.
`get()` and `set()` see the accesses listed in [pointcuts](pointcuts.md#get-and-set) (reads, writes, compound assignments and increments).
They do not see the following.

| Not covered | Why |
|---|---|
| `isset()`, `empty()`, `unset()`, `property_exists()` | Other handlers, not field accesses. |
| Static properties | Not field join points. |
| `foreach ($object as ...)`, `get_object_vars()`, `(array)`, `var_dump()`, `serialize()`, `json_encode()` | Other handlers. |
| Objects created before `enable()` | Only objects created by `new` after `enable()` are wrapped. |
| Objects created without `new`: `unserialize()`, `ReflectionClass::newInstanceWithoutConstructor()`, `PDO` fetch into an object, internal code | They never run the hook that wraps the object. |
| Objects of a class that no `get()` or `set()` pointcut can match | The class is probed once, on `new`, and only matching classes are wrapped. |
| Objects of internal classes with handlers of their own (`Throwable`, `Closure`, `ArrayObject`, `DateTime` and others), and their subclasses | The engine keeps their handlers. |

Other behaviour:

- Accessibility is checked first.
  A property that the accessing code cannot see is not a join point; PHP raises its usual error, or calls `__get()` or `__set()`.
- `__get()` and `__set()` run inside the original access, and their own accesses are join points.
- Clones of wrapped objects stay wrapped.

Known limitation: a property access site that first runs on an uncovered object of a class (for example a getter called on an object created before `enable()`) keeps skipping the advice for covered objects of that class, until an object of another class passes the same site (`ext/tests/105-fields-coverage.phpt`).

### Exception handlers

- `handler()` is before-only.
  The advice's return value is ignored, and `OriginalCall` does nothing.
- An exception thrown by the advice replaces the caught exception.
  It propagates from the `catch` clause, the `finally` block of that `try` runs, and the caught exception is not attached as `previous`.
- Covered: a `catch` clause that catches the exception in flight.
  Not covered: a `try` with `finally` and no matching `catch`, `exit()`, and code in the `AOP\` namespace.
- A `catch` clause whose class is not loaded never matches.

### Initialization

Hook backend only: under `woven`, `Weaver::enable()` rejects every `initialization(...)` pointcut, also for classes with a constructor.

- `initialization()` matches `new` of a class without a constructor.
  Abstract classes, interfaces and enums are not covered: the original code raises its usual error.
- A class that inherits a constructor is matched through the constructor join points, as before.
- `new` in the `AOP\` namespace is not covered.
- Objects created with `unserialize()` or `newInstanceWithoutConstructor()` do not run `new`, so they are not covered.
- If advice throws, `new` is aborted: the object is released without calling its destructor, as with a failed constructor.

### Introductions

`#[DeclareParents]` and `declare-parents` make a class implement an interface, and forward the methods the class does not declare to a default implementation.

- The rules are compile-time.
  `enable()` sends them to the engine, and so does a change to the Weaver while it is enabled.
  Only files compiled afterwards are changed, so load the target classes after `enable()`.
- Opcache stores the changed code with the file.
  After you change the rules, reset opcache (`opcache_reset()`, or restart the server), or later requests keep the old code.
- Covered: class declarations whose name matches the type pattern.
  Not covered: interfaces, traits, enums and anonymous classes.
- A method that the class declares, or that a parent class implements, wins over the default implementation.
- A trait method is shadowed by the default implementation, because traits are unknown at compile time.
- A `static` return type fails, unless the default implementation's method returns the object.
- A `final` parent method with the same name is an inheritance error.
- A woven class implements an interface, so it is no longer early-bound: it cannot be used before its declaration in the same file.
- Parameter attributes are not copied to the introduced methods.
- The Spring `DefaultIntroductionAdvisor` does not introduce the interface. `instanceof` stays `false`, and the interface's methods are not defined on the class. Use `#[DeclareParents]`, `declare-parents` or `Weaver::declareParents()` instead.

## Known issues

These are defects or deviations from AspectJ and Spring that were found while writing these pages.
Each one lists how it shows up, and a workaround where there is one.

### Class loading is not woven

Calls made while PHP autoloads a class are not intercepted, so a broad pattern such as `execution(* *(..))` does not advise the autoloader or the internal functions it calls.
The runtime loads its own classes lazily during dispatch; weaving the autoloader would re-enter the dispatcher.
Set `aop.weave_autoload=1` to weave class loading anyway, with an aspect that cannot recurse into itself.
The setting is `PHP_INI_ALL`, so `ini_set()` at run time works too.

Runaway recursion through advised code raises PHP's `Maximum call stack size` `Error` (PHP 8.3+ stack limit) instead of crashing.

### Compile-time specialisation changes behaviour by namespace

The same call is intercepted in one file and not in another, depending on whether it is namespaced (see [compiler-specialised internal functions](#compiler-specialised-internal-functions)).
Do not rely on advice for internal functions in the global namespace.

## Reporting

If you find a case that is not listed here, open an issue with the smallest script that shows it.
The issue should name the PHP version, the extension version and the output of `php -i`.
