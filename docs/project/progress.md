# Progress log

Plan: [plan.md](plan.md). Contract between engine and runtime: [../internals/contract.md](../internals/contract.md).
Work happens on branch `claude/stoic-goodall-rplkuy`; each stream works in its own local worktree branch and is merged here.

## Status

| Stream | What | Model | Status |
|---|---|---|---|
| 0 | Skeleton, seed copy from phauthentic/aop, contract, stub, FakeEngine | coordinator | done |
| A | C engine core (execute_ex/execute_internal hooks, OriginalCall, decision cache, teardown) | opus | running |
| B | PHP model: join point kinds, signatures, nullable this/target, generalised pointcut interface, programmatic advisors | sonnet | done, merged |
| C | Build & release: Makefile (dist, test-all), Docker, ci.yml, release.yml, semver gate | sonnet | done, merged |
| D | NativeRuntime + Weaver, instantiation models, precedence, declare warning/error, per-instance advice | sonnet | running |
| E | New designators: call, withincode, @withincode, @this, cflow, cflowbelow, if, initialization, adviceexecution, handler, get, set | sonnet | running |
| F | Spring API layer: pointcut/advice/advisor APIs, adapters, target sources, array config, #[Configurable] | sonnet | running |
| G | C experimental: field get/set, handler(), ctor-less initialization, introductions | opus | waits for A |
| H | PHP 8.4/8.5 compatibility, test-all on 8.3/8.4/8.5 × NTS/ZTS | opus | waits for A, C |
| I | Docs + examples | haiku | waits for D, E, F, G |
| J | Final integration and verification | coordinator | pending |

## Log

### 2026-10-23
- Read Spring AOP reference (`core/aop`, `core/aop-api`) from the spring-framework sources (docs.spring.io is blocked by the sandbox) and built the feature matrix in the plan.
- Wave 0 (`3bc91af`): removed the prototype extension and committed autotools output; new layout `ext/` (C) + `src/` (PHP, namespace `AOP\`); seeded `src/` and `tests/` from phauthentic/aop without the proxy layer, bean renamed to service; wrote `docs/internals/contract.md`, `ext/aop.stub.php`, `tests/Support/FakeEngine.php`; extension skeleton builds and its phpt passes on PHP 8.3.
- Sandbox: GitHub zipball downloads are blocked for repositories outside this session, so `vendor/` is installed locally by shallow git fetches at the locked commits (phpstan and php-cs-fixer from their release phars). CI uses plain `composer install`.
- Merged stream B (`5d4c5cb`, `21f8920`) and fixed FakeEngine for phpstan/cs (`986c398`): 552 unit tests green, phpstan level max clean, php-cs-fixer clean. Contract §4 deviations recorded in the contract (`thisObject`, caller-sensitivity of `this`/`@within`, lexical `within`).
- Started streams D, E, F.
- Merged stream C (`df64132`): Makefile targets (ext, test-ext, test, check, test-all, dist, binfmt), `scripts/docker-matrix.sh` + `scripts/container-build.sh`, dev Dockerfile/compose, `ci.yml`, `release.yml` (semver tag gate, Linux glibc/musl × x86_64/arm64, macOS arm64/x86_64, Windows x64/x86 PIE-named zips, SHA256SUMS, GitHub Release), `scripts/semver-check.sh` (+ tests: 15 good / 23 bad tags), `CHANGELOG.md`. Verified: actionlint 1.7.12 clean, shellcheck clean, `make dist` for 8.3 nts/zts glibc amd64 built + phpt green. Not verifiable in the sandbox (containers have no network): musl cells, phpunit inside containers, arm64/QEMU, macOS/Windows runners; those are checked by CI. macOS x86_64 uses `macos-15-intel` (macos-13 runners are retired).

## Still to do
- Merge A; start G and H.
- Before the first tag: move the CHANGELOG `Unreleased` entries into a version section (release notes come from it).
- Merge D, E, F; wire `Weaver::fromConfig()`, target sources and `#[Configurable]` into the Weaver (F's integration points).
- E2E tests with the real extension; valgrind run.
- 8.4/8.5 compatibility; CI green on all cells.
- Docs and examples (I), final review (J).
