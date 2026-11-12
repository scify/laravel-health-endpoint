# Contributing

SciFY maintains this package. Pull requests are welcome.

## Before you open a pull request

Run `composer setup` once. It installs the dependencies, a pinned Gitleaks (checksum verified) and the pre-commit hook.

```bash
composer lint:fix
composer test
```

CI runs `composer lint:check` and the tests; both must pass. The hook runs Gitleaks and the same style checks on the
staged files. Do not add `@phpstan-ignore` comments, baseline entries or inline `@var` overrides: fix the type.
Keep the code compatible with PHP 8.3 and Laravel 12: the CI matrix tests both.

## Adding a check or a probe

A pull request that adds a check or a probe meets every item of this list.

1. **Name.** snake_case, stable, and named after the dependency, not the vendor where the vendor can change (`mail`,
   not `mailgun`). Use a vendor name only when the check is specific to that vendor's API.
2. **Severity.** `failed` only when users are affected; otherwise `warning`.
3. **No side effects.** No message, no event, no write, no quota use. Prefer an endpoint that only checks the
   credentials.
4. **Timeout.** At most 5 seconds. All checks together must stay under 10 seconds.
5. **Disclosure.** Fixed summaries. No exception text, hostnames, account names or credentials in `summary` or
   `meta`. Add `meta` keys to the allow-list in `CheckRegistry` explicitly.
6. **Detection.** An `'auto'` rule that reads drivers, not connection or store names, and never registers a check for a
   service that the app does not use.
7. **Default.** A new shared check defaults to `false` in the release that adds it, in both
   `CheckRegistry::DEFAULTS` and `config/health-endpoint.php`. It can become `'auto'` in a later release, with a
   CHANGELOG entry.
8. **Tests.** `Http::fake()` (or a mocked transport) for each status the probe maps, the connection error, missing
   credentials, and the `'auto'` rule.
9. **Docs.** The check table in the README, and the CHANGELOG.

Where to put it:

| Need | Where |
|---|---|
| A new mail provider | A class that implements `MailProbe`, in `src/Probes/Mail/`, and one line in `options.mail.probes` |
| An external service that several apps use | A `Probe` (usually an `HttpServiceProbe`) and a new shared check name in `CheckRegistry` |
| A service that one app uses | A probe in the app, registered in `extra_checks`. Move it here when a second app needs it. |

A new check name does not change the JSON contract: the schema lists check names as examples. A probe for a transport
that reported `skipped` changes that app's response from `skipped` to a real result; say so in the CHANGELOG.

## The agent skill

`skills/laravel-health-endpoint` is a copy of the skill in SciFY's private `scify-agent-tools` repository
(`scify-dev/skills/laravel-health-endpoint`). Change both copies in the same way, and keep them identical. Pint, Rector
and the pre-commit hook skip the skill: `scripts/detect.php` must also run on PHP 8 and Laravel 8, to report apps
that are too old.

## The JSON contract

`resources/schema/health-response.schema.json` is the source of truth for every SciFY app and the monitor. A new
optional field is a MINOR change of `schema_version` and of the package. A removed or renamed field, or a changed
status rule, is a MAJOR change and needs the monitor to change first.

`ResultMapper::SCHEMA_VERSION` is the only place in the code that sets `schema_version`. The package tests read the
constant. To change the version:

1. Set `ResultMapper::SCHEMA_VERSION` in `src/ResultMapper.php`.
2. In `resources/schema/health-response.schema.json`, add or change the field. For a MAJOR change, also change the
   `schema_version` pattern (`^1\.[0-9]+$`) and the "schema_version 1.x" text in the `description`.
3. Change the version in `README.md`: the status line below the title and the example response.
4. For a MAJOR change, change the major version in `skills/laravel-health-endpoint/references/app-test.md`. That
   test checks the major version only, so a MINOR change needs no edit there. Change the private copy of the skill too
   (see "The agent skill").
5. Add a CHANGELOG entry under a new package version: MINOR for a MINOR change. The package major version follows the
   contract major version.

`composer test` fails when the constant and the schema pattern do not agree: the response tests validate each body
against the schema.
