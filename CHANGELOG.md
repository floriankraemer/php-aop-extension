# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Native aspect-oriented programming extension skeleton (`ext/`) and PHP package (`src/`).
- Build and release tooling: `make ext|test-ext|test|check|test-all|dist`, Docker matrix
  builds for PHP 8.3/8.4/8.5 (NTS and ZTS, glibc and musl), CI and tag-driven release
  workflows with PIE-compatible Windows assets and `SHA256SUMS`.
