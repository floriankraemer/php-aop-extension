# Contributing

Thank you for helping with this project.
These rules apply to every contribution, from maintainers and from outside contributors.

## Commit messages

This repository uses [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/).

```text
<type>(<optional scope>)<!>: <description>

<optional body>

<optional footers>
```

- Use these types: `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`, `revert`.
- Write the description in the imperative mood, in lower case, without a final period.
- Mark a breaking change with `!` after the type or scope, or with a `BREAKING CHANGE:` footer.
- Use the title of a pull request in the same format.

Examples:

```text
feat(pointcut): add the cflowbelow() designator
fix(weaver): keep per-object advice after freeze()
feat!: require PHP 8.4
```

## Versioning

This project follows [Semantic Versioning 2.0.0](https://semver.org/).
The commit types decide the next version:

| Change | Version bump |
|---|---|
| `fix`, `perf` | patch |
| `feat` | minor |
| Any breaking change (`!` or `BREAKING CHANGE:`) | major |

A release tag has the form `vMAJOR.MINOR.PATCH`, with an optional pre-release part.
`make test-scripts` checks the tag grammar that the release workflow enforces.
Do not change the version yourself in a pull request; maintainers tag releases.

## Before you open a pull request

- Run `make check` (php-cs-fixer, phpstan, the phpt suite and phpunit).
  `docs/building.md` explains the build and every test target.
- Add or update tests for the change.
  Line coverage of `src/` and `ext/*.c` must stay at or above 85%.
- Update the documentation in `docs/` when behavior changes.
- Add a line to `CHANGELOG.md` under `[Unreleased]`.
- Keep the change focused: one concern per pull request.
- Fix lint errors and test failures you meet, even when your change did not cause them.
- Do not edit generated files by hand.
