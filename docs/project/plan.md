# Plan: php-aop-extension, a native, Spring/AspectJ-complete AOP engine for PHP

## Context
`floriankraemer/php-aop-extension` is a prototype: one `aop.c` overrides `zend_execute_ex` and prints before/after every call, plus dummy `Pointcut`/`Advice`/`Aspect` classes and committed autotools leftovers.
`phauthentic/aop` is a Spring-style AOP library that weaves with generated proxies. Proxies limit it: no private, static or final methods, no final classes without interfaces, no self-invocation, no constructors or functions, and no `call`/`cflow`/`if` designators.

The goal is the best AOP extension for PHP: everything Spring AOP offers (the @AspectJ style and the Spring AOP API), plus the AspectJ features Spring cannot offer because it is proxy-based. Weaving happens inside the Zend engine, with no proxies.

Your decisions:
- The extension repo owns the whole model: a C engine plus its own PHP package. That package is seeded from phauthentic/aop's code (same author, MIT), then extended and diverged freely. There is no dependency on phauthentic/aop and no attribute compatibility with it.
- "service" replaces "bean" everywhere (`service()` designator, `serviceName`, `registerService`).
- **Hard constraint:** `phauthentic/aop`, `phauthentic/aop-symfony` and `phauthentic/aop-symfony-example` are read-only. No edits, no commits, no pushes, and none of them gets a dependency on this extension or its package. Seeding means copying source text into the extension repo once; afterwards there is no link back to them.
- All work happens in `floriankraemer/php-aop-extension` on branch `claude/stoic-goodall-rplkuy`.

Source for the Spring feature list: Spring's `framework-docs/.../core/aop/**` and `core/aop-api/**` sources (docs.spring.io is blocked by the sandbox proxy, so I cloned them from GitHub).

## Feature matrix (what gets built)

| Spring / AspectJ feature | Native implementation |
|---|---|
| `@Aspect`, `@Pointcut`, named pointcuts and combinators, the 5 advice types, binding, `proceed(args)`, `JoinPoint`/`ProceedingJoinPoint` | Ported from aop. `getThis()`/`getTarget()` become `?object` (null for static methods and functions) |
| Designators `execution within this target args @target @args @within @annotation bean` | Ported; `bean` becomes **`service`** |
| AspectJ-only `call withincode @withincode @this cflow cflowbelow if initialization adviceexecution handler get set` | All new. `get`/`set`/`handler` are marked experimental |
| `preinitialization`, `staticinitialization` | Not applicable in PHP (no forced super-ctor order, no static initializers); they raise a clear error |
| Join points on **private, protected, static and final methods, final/readonly classes, self-invocation, constructors and magic methods** | Engine hook (`execute_ex`) |
| Extra beyond AspectJ: **plain functions** (`execution(* App\Util\fmt*(..))`), **internal functions and methods** (`execution(* PDO::query(..))`, `file_get_contents`) | `execute_ex` + `execute_internal` hooks |
| `@Order`, the `Ordered` interface, same-aspect precedence; AspectJ `declare precedence` | `#[Order]`, `Ordered`, `#[DeclarePrecedence('A, B, *')]` |
| `declare warning/error` | `#[DeclareWarning(pc, msg)]`/`#[DeclareError]`, raised when a join point first matches |
| Instantiation models singleton/perthis/pertarget; AspectJ percflow/percflowbelow/pertypewithin | `#[Aspect(scope: …)]`, all five |
| Introductions `@DeclareParents` | Experimental: `zend_ast_process` hook adds `implements` and delegating methods at compile time. Rules must be registered before the target class compiles; an opcache caveat is documented |
| `AopContext.currentProxy()` | `AopContext::currentJoinPoint()`/`currentObject()`; self-invocation is advised anyway |
| Programmatic `AspectJProxyFactory`, `ProxyFactory` | `Weaver::fromAspects()`, `addAspect()`, `addAdvisor()`, `enable()/disable()` |
| `Advised` (add/remove advisors at runtime, freeze, list) | `Weaver` management API, plus **per-instance advice** `Weaver::advised($obj)->addAdvice(...)` |
| Pointcut API: ClassFilter/MethodMatcher, static vs dynamic, ComposablePointcut, regex, name-match, attribute, ControlFlowPointcut, custom pointcuts | `Pointcut\Api\*` |
| Advice API: MethodInterceptor, MethodBeforeAdvice, AfterReturningAdvice, ThrowsAdvice (typed `afterThrowing`), IntroductionInterceptor, DefaultPointcutAdvisor, Regexp/NameMatch advisors, AdvisorAdapterRegistry (custom advice types) | `Advice\Api\*`, `Advisor\*` |
| TargetSource: hot-swappable, pooling, prototype, ThreadLocal | A stand-in object (`newInstanceWithoutConstructor`, so it works for final classes too) whose calls are rerouted to `TargetSource::getTarget()`. ThreadLocal becomes **Fiber-local** |
| Schema-based (XML) config, global advisors | `Weaver::fromConfig(array)`: aspects as plain classes, pointcuts and advice in config |
| Auto-proxy creators | Inherent; service-name-based auto-advice is done with `service()` |
| `@Configurable`, LTW, `aop.xml` include/exclude | `#[Configurable]` with a configurer callback that runs on initialization; LTW is native; `Weaver` `include`/`exclude` type patterns |
| JoinPoint extras: `getKind()`, `getStaticPart()`, `getEnclosingStaticPart()`, `getSourceLocation()`, `toLongString()` | New |

Not supported (documented): methods returning by reference, compiler-specialised internal functions (`strlen`, `count`, `is_*` … become opcodes, and PHP 8.4 frameless calls), and the JIT (it is disabled when `execute_ex` is overridden).

## Architecture

```
user call ──► aop_execute_ex / aop_execute_internal (C)
               ├─ pass-through: weaver off, guard, bypass flag, generator resume, runtime-internal caller
               ├─ decision cache (callee class+fn [+caller fn if caller-sensitive]) → PHP matcher on miss
               └─ advised → PHP dispatcher(JoinPointDescriptor, args, OriginalCall) → result
                   NativeRuntime: [call-chain] → [execution-chain] → OriginalCall::__invoke($args)
                   → zend_call_function with bypass → original body
               C writes the result into the frame and tears the frame down (TOP-function leave path)
```

### C (`ext/`)
Files: `config.m4`, `php_aop.h`, `aop.c` (module, globals, INI `aop.enable`), `aop_hook.c` (execute_ex/internal, teardown, args), `aop_engine.c` (`Engine`, `OriginalCall`, `JoinPointDescriptor` arrays), `aop_fields.c` (handler wrappers, ZEND_NEW), `aop_catch.c` (ZEND_CATCH), `aop_ast.c` (introductions), `aop_arginfo.h`, `tests/*.phpt`. The prototype and the generated files are deleted.

- Native API `AOP\Engine`: `register(matcher, dispatcher)`, `unregister()`, `reset()`, `isRegistered()`, `setIntroductions(array)`, `enableFieldInterception(bool)`. `OriginalCall::__invoke(array $args)` passes positional args plus string keys as named params, keeps references, sets `function_handler` directly so visibility is bypassed, and uses the object or the called scope.
- Hook details:
  - Args come from the frame: declared slots, extras after `last_var+T`, and `extra_named_params`.
  - Caller info comes from `prev_execute_data` (func, this, file, line).
  - The dispatcher runs with the advised frame still pushed, so backtraces stay correct.
  - Teardown mirrors the PHP 8.3 `zend_leave_helper` TOP branch: free the CVs, symbol table, extra args, named params and the closure ref, restore `EG(current_execute_data)`, and call `zend_observer_fcall_end`. The caller frees `This` and the frame (checked against `ZEND_DO_FCALL` and `zend_call_function`).
  - Re-entrancy guard while the matcher runs. Never intercept a call whose caller is in `AOP\*` (the extension's own runtime namespace).
  - The decision cache is per request and freed in RSHUTDOWN. The matcher returns flags: `ADVISE | TRACK (cflow) | CALLER_SENSITIVE`.
- Fields (experimental): a user opcode handler on `ZEND_NEW` (and a clone wrapper) swaps in wrapped handlers (`read_property`, `write_property`, `get_property_ptr_ptr`) for classes with field advice. These handlers never fill the runtime cache slot, so the VM fast path can't bypass them. Objects created before weaving starts aren't covered. The `ZEND_NEW` handler also emits `initialization` for classes without a constructor.
- `handler()`: a `ZEND_CATCH` user opcode handler emits a before-only join point when the caught exception matches.

### PHP (`src/`, package `floriankraemer/php-aop-extension`, PSR-4 `AOP\` for the PHP package and the native classes alike, e.g. `AOP\Weaver`, `AOP\Attribute\Before`, `AOP\JoinPoint\JoinPointInterface`, requires `ext-aop`)
- The package is seeded by copying phauthentic/aop `src/` and `tests/Unit` with the namespace changed; that keeps pointcut-language coverage. Then:
  - Drop `Proxy\*`.
  - Rename bean → service.
  - Make `this`/`target` nullable.
  - Add `JoinPointKind` and signatures (`MethodSignature`, `FunctionSignature`, `ConstructorSignature`, `FieldSignature`, `CatchClauseSignature`).
  - Extend the lexer, parser and compiler with the new designators and the function-name syntax (no `::`, namespaced name).
- `Runtime\NativeRuntime`:
  - Matcher plus dispatcher.
  - Chain caches per (kind, class, scope, member, service, caller).
  - Arg normalisation to declared params with defaults plus the variadic tail.
  - Return-type check for Around results.
  - A cflow stack per Fiber.
  - Aspect instance scopes.
  - Error-wrapping that names the aspect and method.
- `Weaver`: the front door. It provides `fromAspects`, `fromConfig`, add/remove aspects and advisors, `freeze`, `advised($obj)`, `registerService`/`registerServiceClass`, include/exclude, `targetSource()`, and `enable`/`disable`.
- `Pointcut\Api\*`, `Advice\Api\*`, `Advisor\*`, `TargetSource\*`, and `Attribute\{Configurable, DeclarePrecedence, DeclareWarning, DeclareError}` as in the matrix.

### PHP 8.3, 8.4 and 8.5 support
- The extension must build and pass the phpt and phpunit suites on PHP **8.3, 8.4 and 8.5**, NTS and ZTS.
- `ext/aop_compat.h` hides the engine differences behind `PHP_VERSION_ID` guards:
  - zend_vm leave-path and frame-flag changes. Teardown is re-verified against each version's `zend_vm_def.h`.
  - 8.4 frameless internal calls (`ZEND_FRAMELESS_ICALL_*`) can't be intercepted, so the docs say so. Optionally a user opcode handler can make them intercepted calls.
  - 8.4 property hooks: field join points must respect hooked properties.
  - 8.4 lazy objects: advice must not trigger initialization by accident.
  - observer API signature changes.
  - arginfo macros.
- Local check: `make test-all` runs build plus tests in Docker images `php:8.3-cli`, `php:8.4-cli`, `php:8.5-cli` and their `-zts` variants.

### Build and release
- `make dist`: a Docker-driven matrix that builds `aop.so` for PHP 8.3/8.4/8.5 × NTS/ZTS × glibc (Debian `php:X-cli`/`-zts`) and musl (`-alpine`), for the host architecture. Pass `PLATFORMS=linux/amd64,linux/arm64` to use buildx/QEMU. Each build runs the phpt suite before it is packaged. Output goes to `dist/aop-<version>-php<X.Y>-<nts|zts>-linux-<glibc|musl>-<arch>.tar.gz` (containing `aop.so`, `LICENSE`, `README.md`, and `aop.ini` with `extension=aop`), plus `dist/SHA256SUMS`. The version comes from `git describe --tags` or `VERSION=`.
- `.github/workflows/ci.yml` (push and PR): the PHP 8.3/8.4/8.5 × NTS/ZTS matrix on Linux, plus macOS and Windows smoke builds. It builds and runs phpt, phpunit, phpstan, php-cs-fixer, and one valgrind job.
- `.github/workflows/release.yml` triggers on tags matching semver `v?MAJOR.MINOR.PATCH[-prerelease][+build]`; a first job validates the tag with a regex and fails otherwise. Matrix, each cell with a phpt run before packaging:
  - Linux glibc and musl, x86_64 and arm64 (`ubuntu-24.04` and `ubuntu-24.04-arm`, building inside `php:X(-zts)(-alpine)` containers), NTS and ZTS.
  - macOS arm64 (`macos-14`) and x86_64 (`macos-13`), via `shivammathur/setup-php` (`phpts: nts|ts`).
  - Windows x64 and x86, NTS and TS, via `php/php-windows-builder/extension`. Assets are named in the PIE convention: `php_aop-<ver>-<X.Y>-<ts|nts>-<vs16|vs17>-<arch>.zip`.
  - A final job collects every archive, writes `SHA256SUMS`, and creates the GitHub Release with `softprops/action-gh-release`. A `-prerelease` tag marks the release as a prerelease. Release notes come from `CHANGELOG.md`.
- `composer.json` gets `"type": "php-ext"` and `php-ext` metadata (`extension-name: aop`, `build-path: ext`), so users can install it with PIE.

### Repo housekeeping
- `Dockerfile` (PHP 8.3), `docker-compose.yaml`, `Makefile` (`ext`, `test-ext`, `test`, `check`).
- `composer.json` with php-cs-fixer, phpstan and phpunit like aop.
- Fix the `.gitignore`.
- `README.md` plus `docs/` (concepts, pointcuts, advice, programmatic API, target sources, a Spring→this mapping table, limitations, internals).

## Parallel execution

### Orchestration
- I coordinate. I write the contract and the skeleton, spawn agents, review each result, merge, run the full verification, and push. The agents never push and never touch the three phauthentic repos; the hard constraint is repeated in every agent prompt.
- Each workstream gets its own local git worktree of the extension repo: `/home/user/wt/<id>`, on local branch `claude/stoic-goodall-rplkuy-<id>`, branched from the skeleton commit. Agents commit only there. I merge into `claude/stoic-goodall-rplkuy`, and only that branch is pushed.
- File ownership is disjoint per stream (listed below), so merges don't conflict. Only I edit shared files (`ext/config.m4` source list, `ext/aop.c` registration, `composer.json`).
- Model choice: **opus** for Zend-engine C (memory safety, VM internals), **sonnet** for PHP architecture and the CI/build tooling, **haiku** for mechanical porting and docs.

### Wave 0: contract and skeleton (me, sequential, small)
Commit to `claude/stoic-goodall-rplkuy`:
- Repo layout: `ext/`, `src/`, `tests/`, `docs/`, `examples/`. Delete the prototype files. Add the `.gitignore` and `composer.json` (PSR-4, dev tools, PIE metadata).
- `ext/aop.stub.php`: the native API signatures (`Engine`, `OriginalCall`, flag constants).
- `docs/internals/contract.md` is the C↔PHP contract every stream codes against:
  - the matcher/dispatcher signatures;
  - the `JoinPointDescriptor` array shape (`kind`, `class`, `scope`, `name`, `static`, `caller{function,class,object,file,line}`, `field`, `exception`);
  - the return flags `ADVISE|TRACK|CALLER_SENSITIVE`;
  - the args array shape;
  - `OriginalCall` semantics;
  - the `JoinPointKind` enum values;
  - the `RuntimeMatchContext` additions (`caller`, `cflow` stack accessor, `kind`).
- `tests/Support/FakeEngine.php`: a pure-PHP stand-in that drives the matcher and dispatcher. PHP streams can then test end-to-end without the compiled extension.

### Wave 1: start in parallel right after Wave 0

| ID | Stream | Model | Owns | Done when |
|---|---|---|---|---|
| A | **C engine core**: execute_ex/execute_internal hooks; methods of every visibility, static, final, ctor, magic, functions, internal functions; `OriginalCall`; bypass, guard, decision cache; args extraction; caller info; frame teardown; `Engine` class | opus | `ext/aop.c`, `aop_hook.c`, `aop_engine.c`, `php_aop.h`, `aop_arginfo.h`, `ext/tests/0*.phpt` | phpt green on 8.3 covering visibility, static, final, self-invocation, recursion, by-ref, variadic/named/defaults, generators, exceptions, the call_user_func/array_map/Reflection paths, internal functions; a valgrind spot check is clean |
| B | **PHP model port**: copy aop `src/` and `tests/Unit` into the new namespace, drop `Proxy\*`, rename bean→service, make this/target nullable, add `JoinPointKind` and the signature classes | haiku | `src/{Advice,Advisor,Aspect,Attribute,Binding,Exception,Interceptor,JoinPoint,Pointcut}/**`, `tests/Unit/**` (ported) | ported phpunit green; phpstan max and cs clean |
| C | **Build & release tooling**: `Makefile` (`ext`, `test-ext`, `test`, `test-all`, `dist`, `check`), `docker/` build images, `ci.yml`, `release.yml` (semver tag gate, full OS/arch/PHP/TS matrix, PIE asset names, SHA256SUMS, GitHub Release), `Dockerfile`/compose refresh | sonnet | `Makefile`, `docker/**`, `Dockerfile`, `docker-compose.yaml`, `.github/**`, `scripts/**`, `CHANGELOG.md` | `actionlint` clean; the tag regex is unit-checked; `make dist` works with the current `ext/` skeleton (tries `dockerd`, else validates by dry-run) |

### Wave 2: each starts as soon as its dependencies are merged

| ID | Stream | Model | Depends on | Owns | Done when |
|---|---|---|---|---|---|
| D | **Runtime + Weaver**: `NativeRuntime` (matcher/dispatcher, chain caches, arg normalisation, return-type check, call→execution layering, per-Fiber cflow stack, AopContext), `Weaver` (fromAspects, add/remove, freeze, services, include/exclude, enable/disable), five instantiation models, `DeclarePrecedence`/`DeclareWarning`/`DeclareError`, `Ordered` | sonnet | B (+ A for e2e) | `src/Runtime/**`, `src/Weaver.php`, `src/Attribute/Declare*.php`, `tests/Unit/Runtime/**`, `tests/E2E/**` | FakeEngine tests green; once A is merged, e2e with the real extension green (private/static/final/function/internal) |
| E | **New designators**: lexer/parser/compiler + pointcuts for `call withincode @withincode @this cflow cflowbelow if initialization adviceexecution get set handler service`, function-name patterns, a clear error for `preinitialization`/`staticinitialization` | sonnet | B | `src/Pointcut/**` (new files and the expression layer), `tests/Unit/Pointcut/**` (new) | unit tests for the static and runtime halves via the contract's `RuntimeMatchContext` |
| F | **Spring API layer**: `Pointcut\Api` (ClassFilter/MethodMatcher, static/dynamic bases, Composable, Regex, NameMatch, Attribute, ControlFlow), `Advice\Api` (MethodInterceptor, MethodBeforeAdvice, AfterReturningAdvice, ThrowsAdvice, IntroductionInterceptor), advisors, AdvisorAdapterRegistry, per-instance `advised()`, `Weaver::fromConfig`, `TargetSource\*` (HotSwappable, Pool, Prototype, FiberLocal), `#[Configurable]` | sonnet | B; integrates with D through the `Weaver` extension points named in the contract | `src/{Pointcut/Api,Advice/Api,TargetSource,Config}/**`, `src/Advisor/Api/**`, `src/Attribute/Configurable.php`, `tests/Unit/{Api,TargetSource,Config}/**` | unit tests green with FakeEngine |
| G | **C experimental join points**: field get/set (ZEND_NEW opcode handler, wrapped object handlers, clone), `handler()` (ZEND_CATCH), ctor-less `initialization`, introductions via `zend_ast_process` | opus | A | `ext/aop_fields.c`, `aop_catch.c`, `aop_ast.c`, `ext/tests/1*.phpt` | phpt green; a valgrind spot check is clean |
| H | **PHP 8.4/8.5 compat**: `aop_compat.h`; re-verify teardown and flags against the 8.4/8.5 `zend_vm_def.h`; frameless calls, property hooks, lazy objects, observer changes; make `make test-all` green on 8.3/8.4/8.5 × NTS/ZTS | opus | A, C (G before its final pass) | `ext/aop_compat.h` + `#if` guards in A/G files (coordinated by me) | `make test-all` green, or CI green if no Docker |

D, E, F and G run concurrently. H starts once A and C are merged and does a final pass after G.

### Wave 3
| ID | Stream | Model | Depends on | Owns |
|---|---|---|---|---|
| I | **Docs + examples**: `README.md`, `docs/` (concepts, pointcuts, advice, programmatic API, target sources, Spring→native mapping, limitations, internals), an `examples/` demo with one feature per matrix row, `ext/tests/900-demo.phpt` | haiku | D, E, F, G | `README.md`, `docs/**` (not the contract), `examples/**` |
| J | **Final integration and verification** | me | all | everything; fixes after an independent review |

Cost notes: haiku does the large mechanical port (B) and the docs (I). Opus is reserved for the three C streams where a subtle bug means a segfault. Sonnet covers PHP design and CI. If haiku's port fails phpstan or tests, it escalates to sonnet for that stream only.

## Verification
- `cd ext && phpize && ./configure --enable-aop && make && make test`: every phpt passes. Spot-check leaks with `USE_ZEND_ALLOC=0 valgrind`.
- `composer install && php -d extension=ext/modules/aop.so vendor/bin/phpunit`: the ported aop suite plus new suites for each matrix row, with end-to-end tests that advise real private, static and final code. Also `composer stan` and `composer cs`.
- `make test-all` is green for 8.3/8.4/8.5 × NTS/ZTS. The Docker CLI is installed here but no daemon is running. I'll try to start `dockerd`. If that fails, only 8.3 NTS is verified locally. The other versions are verified by `ci.yml` after the push, and any failures get fixed.
- `make dist` produces the archives and `SHA256SUMS`. `release.yml` is validated with `actionlint` and the tag regex is unit-checked. A real release run happens only when you push a tag.
- A demo script (`examples/`) that exercises one feature per matrix row, with its output checked into a phpt.
