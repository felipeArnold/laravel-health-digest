# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.1] - 2026-10-10

### Fixed

- Slow queries on PostgreSQL and SQLite no longer collapse into one group: the
  double-quoted identifiers of those grammars were masked as literals, so
  `select * from "orders"` and `select * from "invoices"` produced the same
  sample. Double-quoted text that is not an identifier is still masked.
- Errors logged by a previous release of a zero-downtime deploy (Forge,
  Envoyer, Deployer) keep a relative location. The absolute release path used
  to change the fingerprint on every deploy and merged all framework errors
  thrown inside `vendor/` into a single group.

## [0.2.0] - 2026-10-10

### Added

- Slow queries dashboard at `/health-digest/slow-queries`, with filters for
  period, tenant and SQL text, protected by the `viewHealthDigest` gate.
- `dashboard.enabled`, `dashboard.path` and `dashboard.middleware` options.
- Laravel Boost MCP server for development, through Testbench.

### Changed

- `ReadLogEntries::handle()` accepts `null` as the error log path to skip it.
- `BuildDigest::rank()` is now public.

## [0.1.1] - 2026-10-06

### Removed

- Laravel 11 support.

## [0.1.0] - 2026-10-06

### Added

- Slow query and slow request logging to a daily JSON channel.
- Origin attribution for routes, Livewire components, queued jobs and commands.
- `health:digest` command that groups slow queries, slow requests and errors
  into a ranked JSON digest.

[0.2.1]: https://github.com/felipeArnold/laravel-health-digest/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/felipeArnold/laravel-health-digest/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/felipeArnold/laravel-health-digest/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/felipeArnold/laravel-health-digest/releases/tag/v0.1.0
