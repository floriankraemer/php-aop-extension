# Progress log

Plan: [plan.md](plan.md). Contract between engine and runtime: [../internals/contract.md](../internals/contract.md).
Work happens on branch `claude/stoic-goodall-rplkuy`; each stream works in its own local worktree branch and is merged here.

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
| G | C experimental: field get/set, handler(), ctor-less initialization, introductions | opus | running |
| H | PHP 8.4/8.5 compatibility, test-all on 8.3/8.4/8.5 × NTS/ZTS, opcache inlining, JIT notice | opus | done, merged |
| I | Docs + examples | haiku | done, merged (G features to be patched after G) |
| K | Fix stream I's findings (ControlFlowPointcut, ctor matching, modifiers, regexp, `||` bindings, naming) | sonnet | running |
| J | Final integration and verification | coordinator | pending |

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

## Still to do
- Fix the remaining findings of stream I: Spring `ControlFlowPointcut` matches call kinds and is never TRACKed; `execution(Base::__construct(..))` matches subclass constructors (constructors do not override in AspectJ); method patterns reject `private`/`static`/`final` modifiers; `RegexpMethodPointcut` silently ignores delimited patterns; `||` branches binding different names are not rejected; docblocks still say "bean"; `AdvisorAdapterInterface::getInterceptor()` naming. (Not a bug: `execution(* Base::run(..))` matching an override of `run` is AspectJ semantics.)
- Document the INI settings (`aop.enable`, `aop.disable_inlining`, `aop.weave_autoload`) in README/docs.
- Merge G and H.
- Before the first tag: move the CHANGELOG `Unreleased` entries into a version section (release notes come from it).
- E2E: re-run after G (ctor-less initialization, fields, handlers, introductions end to end through the Weaver); valgrind run of the phpunit e2e suite.
- CI green on all cells after the push (musl, arm64, macOS, Windows, phpunit in containers were not verifiable in the sandbox).
- Docs and examples (I), final review (J).
