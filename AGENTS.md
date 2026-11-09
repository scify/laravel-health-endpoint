# AGENTS.md

Instructions for AI coding agents that change this package. People read [README.md](README.md) first.

`scify/laravel-health-endpoint` gives SciFY's Laravel apps one token-protected `GET /health` with one JSON contract.
It builds on `spatie/laravel-health`. The repository is public: write no internal app names, hostnames, servers,
deploy details or credentials into code, tests, docs or commit messages. The internal specification stays outside
this repository.

## Rules

1. **Compatibility.** The code runs on PHP 8.3 and Laravel 12, and on the newest versions (the CI matrix tests PHP
   8.3–8.5, Laravel 12 and 13, lowest and stable dependencies). Rector stays on
   `LaravelLevelSetList::UP_TO_LARAVEL_120`: the composer-based sets add Laravel 13-only code.
2. **The JSON contract.** `resources/schema/health-response.schema.json` is the source of truth for every app and the
   monitor. Before you change a field, a status or an HTTP code, read "The JSON contract" in
   [CONTRIBUTING.md](CONTRIBUTING.md): it decides the `schema_version` and package version change.
3. **Checks and probes.** Follow the checklist in [CONTRIBUTING.md](CONTRIBUTING.md), "Adding a check or a probe".
   Add a new check to `CheckRegistry::DEFAULTS` with `false`: the defaults also apply to apps that published an older
   config, and an update must not add a check to them. A `meta` key reaches the response only through the allow-list
   in `CheckRegistry`.
4. **Independence from the app.** The package keeps its own check list and its own heartbeat cache keys
   (`health-endpoint:…`). Leave `Health::checks()` and Spatie's heartbeat keys to the app.
5. **Static analysis.** Fix the type when PHPStan reports an error. No `@phpstan-ignore`, no baseline, no inline
   `@var` overrides.
6. **The agent skill.** `skills/laravel-health-endpoint` is a copy of the skill in the private `scify-agent-tools`
   repository. Keep the two copies identical; see [CONTRIBUTING.md](CONTRIBUTING.md), "The agent skill".
7. **Docs.** Write README, CONTRIBUTING and CHANGELOG text in ASD-STE100 Simplified Technical English, like the
   existing text. Add every user-visible change to `CHANGELOG.md` under a new version heading.
8. **Releases.** Maintainers tag and release. Do not create or push tags.

## Commands

```bash
composer lint:fix     # Rector, Pint, then PHPStan; run before every commit
composer lint:check   # what CI runs; includes PHPStan
composer test         # Pest with Orchestra Testbench
```

Tests are Pest, in `tests/Feature` (through the HTTP stack or the container) and `tests/Unit`. Use the fixtures in
`tests/Fixtures` (`ScriptedCheck`, `ScriptedProbe`) for scripted outcomes, and `Http::fake()` for probes. A response
test validates the body against the JSON Schema with the helper in `tests/TestCase.php`.
