# Progress log

Plan: [plan.md](plan.md). Contract between engine and runtime: [../internals/contract.md](../internals/contract.md).
Work happens on branch `initial-implementation`; each stream works in its own local worktree branch and is merged here.

## Status

| Stream | What | Model | Status |
|---|---|---|---|
| 0 | Skeleton, seed copy from phauthentic/aop, contract, stub, FakeEngine | coordinator | done |
| A | C engine core (execute_ex/execute_internal hooks, OriginalCall, decision cache, teardown) | opus | done, merged |
| B | PHP model: join point kinds, signatures, nullable this/target, generalised pointcut interface, programmatic advisors | sonnet | done, merged |
| C | Build & release: Makefile (dist, test-all), Docker, ci.yml, release.yml, semver gate | sonnet | done, merged |
| D | NativeRuntime + Weaver, instantiation models, precedence, declare warning/error, per-instance advice | sonnet | done, merged |
| E | New designators: call, withincode, @withincode, @this, cflow, cflowbelow, if, initialization, adviceexecution, handler, get, set | sonnet | done, merged |
| F | Spring API layer: pointcut/advice/advisor APIs, adapters, target sources, array config, #[Configurable] | sonnet | done, merged |
| G | C experimental: field get/set, handler(), ctor-less initialization, introductions | opus | done, merged |
| H | PHP 8.4/8.5 compatibility, test-all on 8.3/8.4/8.5 × NTS/ZTS, opcache inlining, JIT notice | opus | done, merged |
| I | Docs + examples | haiku | done, merged (G features to be patched after G) |
| K | Fix stream I's findings (ControlFlowPointcut, ctor matching, modifiers, regexp, `||` bindings, naming) | sonnet | done, merged |
| L | Fix field interception on PHP 8.4/8.5 (4 phpt fail there, `const` warnings); hooked properties, lazy objects | opus | done, merged |
| M | Docs for fields/handler/initialization/introductions, INI settings, CHANGELOG, examples 27–30 | haiku | done, merged |
| J | Final integration and verification | coordinator | done |

## Log

### 2026-10-23
- Read Spring AOP reference (`core/aop`, `core/aop-api`) from the spring-framework sources (docs.spring.io is blocked by the sandbox) and built the feature matrix in the plan.
- Wave 0 (`3bc91af`): removed the prototype extension and committed autotools output; new layout `ext/` (C) + `src/` (PHP, namespace `AOP\`); seeded `src/` and `tests/` from phauthentic/aop without the proxy layer, bean renamed to service; wrote `docs/internals/contract.md`, `ext/aop.stub.php`, `tests/Support/FakeEngine.php`; extension skeleton builds and its phpt passes on PHP 8.3.
- Sandbox: GitHub zipball downloads are blocked for repositories outside this session, so `vendor/` is installed locally by shallow git fetches at the locked commits (phpstan and php-cs-fixer from their release phars). CI uses plain `composer install`.
- Merged stream B (`5d4c5cb`, `21f8920`) and fixed FakeEngine for phpstan/cs (`986c398`): 552 unit tests green, phpstan level max clean, php-cs-fixer clean. Contract §4 deviations recorded in the contract (`thisObject`, caller-sensitivity of `this`/`@within`, lexical `within`).
- Started streams D, E, F.
- Merged stream C (`df64132`): Makefile targets (ext, test-ext, test, check, test-all, dist, binfmt), `scripts/docker-matrix.sh` + `scripts/container-build.sh`, dev Dockerfile/compose, `ci.yml`, `release.yml` (semver tag gate, Linux glibc/musl × x86_64/arm64, macOS arm64/x86_64, Windows x64/x86 PIE-named zips, SHA256SUMS, GitHub Release), `scripts/semver-check.sh` (+ tests: 15 good / 23 bad tags), `CHANGELOG.md`. Verified: actionlint 1.7.12 clean, shellcheck clean, `make dist` for 8.3 nts/zts glibc amd64 built + phpt green. Not verifiable in the sandbox (containers have no network): musl cells, phpunit inside containers, arm64/QEMU, macOS/Windows runners; those are checked by CI. macOS x86_64 uses `macos-15-intel` (macos-13 runners are retired).

- Merged stream A (`c4ea6b7`, `c779d15`, `8a629c4`): `AOP\Engine`, `AOP\OriginalCall`, execute_ex/execute_internal hooks, decision cache with the caller-sensitive protocol, raw args, frame teardown. 24 phpt pass (+1 opcache-only), valgrind clean (with and without opcache), `-Wall -Wextra` clean. Engine decisions recorded in the contract ("Engine notes (stream A)"). Known limits: opcache inlines `return <const>;` functions (pass 16), JIT is disabled while hooks are active, frame-inspecting internals (`compact`, `extract`, ...) break when advised.
- Started streams G (fields, handlers, introductions in C) and H (8.4/8.5/ZTS compat, opcache inlining, JIT).

- Merged stream E (`e3819b8`): `call`, `withincode`, `@withincode`, `@this`, `cflow`, `cflowbelow`, `if`, `initialization`, `adviceexecution`, `handler`, `get`, `set`; `preinitialization`/`staticinitialization` rejected with an explanation. Runtime API: `ControlFlowPointcuts::of()`, `OutermostAwareInterface`, `if()` resolution against the declaring aspect. Contract section "Pointcut notes (stream E)".
- Merged stream F (`5f75ab7`): `Pointcut\Api` (ClassFilter/MethodMatcher, static/dynamic bases, composable, regexp, name-match, attribute, control-flow, expression), `Advice\Api` (MethodInterceptor, before, after-returning, after, ThrowsAdvice, introduction interceptor), `Advisor\Api` (adapter registry, default/regexp/name-match/introduction advisors, `toAdvisor()`), target sources (singleton, hot-swappable, prototype, pooling, fiber-local, stand-in), `Config\ConfigLoader` (schema-style arrays), `#[Configurable]`. Contract section "Spring API integration (stream F)".
- After merging E and F: 1051 unit tests, phpstan max and cs clean. Settled in the contract: ctor-less `initialization` passes the new object as `$this`.

- Merged stream D (`7c80c2d` … follow-up): `AOP\Weaver` (fromAspects, fromConfig/addConfig, add/remove aspects and advisors, advised(), services, include/exclude, freeze, enable/disable, targetSource(), configurable()), `NativeRuntime` (matcher/dispatcher, call→execution layering, arg normalisation, return-type check, per-Fiber cflow stack and AopContext), scopes singleton/perthis/pertarget/percflow/percflowbelow/pertypewithin, `Ordered`, `#[DeclarePrecedence]`, `#[DeclareWarning]`/`#[DeclareError]`, adviceexecution, outermost constructors. Gates on main: 1146 unit tests, 20/21 e2e with the real extension (1 skipped until stream G emits ctor-less `initialization`), phpstan max and cs clean. Smoke test: final class, private method via self-invocation, static method and a plain function all advised with no proxy.

- Merged stream H (`05896c8`): `ext/aop_compat.h`; phpt green on 8.3.36/8.4.27/8.5.12 × NTS/ZTS in Docker (0 warnings with `-Wall -Wextra`), valgrind clean on 8.4 and 8.5 (natively built CLIs). No engine structure differences affecting the hooks between 8.3 and 8.5. Frameless calls are not emitted while the hook is installed; property hooks pass through; lazy objects are not initialised by advice. New INI `aop.disable_inlining` (default 1) stops opcache from inlining functions so they stay advisable. Startup notice and phpinfo rows explain that the JIT is off while aop is active (`aop.enable=0` keeps it).
- phpt now runs a second pass with opcache enabled (`scripts/opcache-args.sh`, used by `make test-ext` and `scripts/container-build.sh`, so CI and release builds get it too). Locally on 8.3: 24 pass plain, 29 pass with opcache, 0 failures; e2e 20/21.

- Merged stream I (`20df1e9`): README, `docs/` user guide (concepts, pointcuts, advice, weaver, spring-api, target-sources, configuration, spring-mapping, limitations, building), 26 runnable examples in `examples/` whose output is checked. While writing them it found bugs (below).
- Fixed a segfault found by stream I: `execution(* *(..))` advised the internal functions the Composer autoloader calls while the runtime autoloaded its own classes, recursing until the C stack overflowed. The engine now skips interception while a class is being autoloaded (INI `aop.weave_autoload`, default 0) and passes through when the PHP 8.3+ stack limit is reached, so runaway recursion raises PHP's stack `Error`. Tests 026, 027.

- Merged stream G (`1284cec`, `a7e1bf2`, `c81ee9a`): field get/set via a `ZEND_NEW` opcode handler and per-class wrapped object handlers (reads, writes, compound assignments; isset/unset/static properties/`get_properties` users not covered), ctor-less `initialization`, `exception-handler` via `ZEND_CATCH` (before-only; a dispatcher exception replaces the caught one), introductions by compile-time AST weaving (`zend_ast_process`) with lazily created default implementations. Contract section "Engine notes (stream G)". Merge conflicts with H resolved (both kept). After the merge on 8.3: phpt 40 plain / 46 with opcache, 0 failures; e2e 21/21 (ctor-less `#[Configurable]` now passes against the real engine); unit 1146.

- Docker check of the merged engine on PHP 8.4.27/8.5.12 × NTS/ZTS (glibc, amd64): builds, but 4 phpt fail on every 8.4/8.5 cell (`102-fields-readonly-typed`, `105-fields-coverage`, `107-fields-guards`, `205-lazy-objects`) and `aop_fields.c` has `-Wdiscarded-qualifiers` warnings. Stream G had only built on 8.3. Started stream L to fix it.

- Valgrind (8.3.6 NTS, `USE_ZEND_ALLOC=0`) over the merged engine: 40 passed plain and 45 with opcache, 0 failed, 0 leaked.

- Merged stream K: Spring `ControlFlowPointcut` restricted to execution kinds and tracked on its own (also inside `ComposablePointcut`/`ExpressionPointcut`); constructors no longer "override" (`execution(* Base::__construct(..))` matches only Base's own constructor, `Base+` for subtypes); method patterns accept `public protected private static final abstract` with `!`; `RegexpMethodPointcut` accepts delimited PCRE and throws `\InvalidArgumentException` on invalid patterns; `||` binding errors name the expression; adapters may return the Spring-style interceptor; `ConfigurableInterceptor` uses `isOutermost()`. Gates: unit 1343, e2e 24/24, phpstan max and cs clean, all 26 examples match.

- Merged stream M (`8e4db10`): docs for fields, `handler()`, ctor-less initialization and introductions (covered/uncovered semantics), php.ini settings section, CHANGELOG `Added` list, examples 27–30.
- Added `scripts/check-examples.php` + `make test-examples` (also part of `make check` and CI): all 30 examples match their documented output.

- Added `Weaver::declareParents(typePattern, interface, defaultImpl)`, the programmatic form of `declare parents` (the Spring `DefaultIntroductionAdvisor` only routes calls, because its class filter cannot run before the class is compiled; docs point to `declareParents()`). Unit tests + an E2E test against the real engine (`instanceof` true, default implementation answers). Gates: unit 1346, e2e 25/25, phpstan max and cs clean.

- Merged stream L (`abbc72f`): the 4 failures were 8.4 message/closure-name changes and a test predating fields; fixed `const` (8.5 `EG(fake_scope)`) and `-Warray-bounds` warnings; 8.4+ readonly/asymmetric-visibility checks; hooked properties (one join point around the hooked access, backing-value accesses inside hooks are not join points); lazy objects (advice does not initialise; clones); 8.5 `clone with`. New tests 210–212.
- Re-ran the Docker matrix on the merged branch: 8.4.27 NTS/ZTS 47 plain / 53 opcache, 8.5.12 NTS/ZTS 54 / 55, 0 failures, 0 warnings, packaged with SHA256SUMS. On 8.3: phpt 40 / 46, e2e 25/25, 30 examples match. Docs updated: 8.4/8.5 no longer "in progress".

- Final checks: `composer validate`, actionlint, semver tests, phpstan max, cs, unit 1346, e2e 25/25, phpt 40/46 (8.3), Docker matrix 8.4/8.5 NTS/ZTS green, 30 examples match. Removed the unused `tools/coverage-check.php` copied from phauthentic/aop.

## Still to do
- First CI run on GitHub: musl, arm64, macOS, Windows and phpunit inside containers could not run in the sandbox (no container network, no macOS/Windows).
- Before the first tag: move the CHANGELOG `Unreleased` entries into a version section (release notes come from it).
- Opcache inlining with `aop.disable_inlining=0` behaved inconsistently in manual runs (phpt 200 passes); only matters when the default protection is switched off.
- Valgrind of the field code on 8.4/8.5 (the images have no valgrind).
- Not covered by design (documented in `docs/limitations.md`): JIT while aop is active, compiler-specialised internals (`strlen`, `count`, …), frame-inspecting internals (`compact`, `extract`), by-ref returns, closures, isset/unset/static properties, objects not created with `new`.
