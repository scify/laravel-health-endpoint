# Changelog

All notable changes to `scify/laravel-health-endpoint` are documented here. The package follows Semantic Versioning.
The major version follows the major version of the JSON contract (`schema_version`).

## [Unreleased]

Documentation and repository only. The package code does not change.

### Added

- README badges: Packagist version, CI status, Codecov coverage, PHP version, downloads and license.
- A bug report form and a link to private vulnerability reports, in `.github/ISSUE_TEMPLATE/`.

## [0.1.0] - 2026-11-09

First release.

### Added

- Token-protected `GET /health` with a per-IP limiter before the token check and a global limiter after it.
- The shared JSON contract, `schema_version` 1.0, with its JSON Schema in `resources/schema/`.
- Checks: `database`, `cache`, `mail`, `queue`, `schedule`, `disk`, `debug_mode`, `environment`, `redis`,
  `error_tracker`, each with `'auto'`, `true` or `false`.
- Mail probes for SMTP and Mailgun (API key or SMTP credentials).
- Error tracker probe: an empty Sentry envelope that checks the DSN key without creating an event.
- Heartbeat commands with their own cache keys, scheduled by the package.
- App checks through `extra_checks`: a probe with a severity, or a Spatie check class with a `meta` allow-list.
- `HttpServiceProbe`, a base class for probes of HTTP services.
