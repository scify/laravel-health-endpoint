---
name: laravel-health-endpoint
description: Add the SciFY GET /health endpoint to a Laravel 12 or 13 app by installing the package scify/laravel-health-endpoint, then confirm the production drivers, set the few overrides detection cannot know, wire the deploy and verify. Use when the user asks to add a health endpoint, /health, a monitoring endpoint or a health check route. `/laravel-health-endpoint audit` reports the plan without changing anything.
disable-model-invocation: false
user-invocable: true
argument-hint: "[audit]"
allowed-tools: Bash, Read, Edit, Write, Glob, Grep, AskUserQuestion
---

# Add the SciFY `/health` endpoint

**Last updated:** 2026-11-09

Install [scify/laravel-health-endpoint](https://github.com/scify/laravel-health-endpoint) in the Laravel app in the
current working directory. The package provides the route, the token gate, the rate limiters, the checks, the
heartbeats and the shared JSON contract that the SciFY monitoring app reads. This skill does the app-specific part:
the production values, the overrides, the deploy and the verification.

## Modes

Parse `$ARGUMENTS`:

| Argument | Mode | Behaviour |
|---|---|---|
| (empty) | Full | Run all phases. Ask before every change. |
| `audit` | Audit | Run Phases 1 to 4, report the plan, change nothing. |

## Rules

1. **The package is the source of truth.** Read its README at the installed or latest version before Phase 3: the
   check table, the `'auto'` rules, the config keys and the security rules are there. Do not copy package code into
   the app, and do not reimplement a check that the package has.
   ```bash
   gh api 'repos/scify/laravel-health-endpoint/contents/README.md' -H 'Accept: application/vnd.github.raw'
   ```
2. **Change only what detection cannot know.** The package selects checks at runtime from the production config.
   Override a check only with evidence (a file and a line, or a developer answer).
3. **Respect the target repository.** Its `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md` and `tests/README.md` win over
   this skill's examples: Composer script names, test style, static analysis level, folders.
4. **Supply-chain gates are the developer's decision.** Never bypass a Composer cooldown or a Vet review without a
   clear yes (Phase 6, step 1).
5. **Report, do not fix, outside scope.** `TrustProxies`, server config (`expose_php`), dev tools in `require` and
   deploy steps outside the `.env` template are reported in the hand-over. Change them only when the developer asks.
6. **No suppressions.** No `@phpstan-ignore`, no baseline entries, no skipped tests.
7. **Audit mode writes nothing.** No `composer require`, no `vendor:publish`, no file edits.

## Phase 1: Detect

Read only.

**Command prefix.** Check how the project runs commands, in this order:

1. `APP_DEVELOPMENT_ENV` in `.env` (`native` or `ddev`), if the key exists.
2. A `.ddev/` directory → prefix with `ddev` (`ddev composer`, `ddev artisan`, `ddev exec`).
3. `vendor/bin/sail` and a running Sail stack → prefix with `vendor/bin/sail`.
4. Otherwise run natively.

**App facts.** Run the detection script from the app root. Use these commands as-is:

```bash
php < "${CLAUDE_SKILL_DIR}/scripts/detect.php"
```

With DDEV, pipe the script into the container (the container cannot see the skill directory):

```bash
ddev exec php < "${CLAUDE_SKILL_DIR}/scripts/detect.php"
```

The script prints the PHP and Laravel versions, whether the package or `spatie/laravel-health` is installed, the
LOCAL drivers, whether a Sentry DSN is set, `proc_open`, `expose_php`, and the routes that contain `health` or are
`/up`. It prints no secrets.

**Stop conditions.** Stop and report if any of these is true:

- Laravel older than 12, or PHP older than 8.3. The package does not support them; the app upgrades first.
- The package is already installed. Switch to a review: compare its config with Phases 2 and 3 and report.
- A route at exactly `/health` that the package did not register. Ask whether the app's own implementation is
  replaced. If yes, the plan removes it in Phase 6 (route, controller, middleware, provider, limiters, heartbeat
  schedules, config). Other routes that the script lists, such as Ignition's `/_ignition/health-check`, are not a
  conflict.

**More evidence.** Collect with file and line:

- `composer.json`: `config.policy.cooldown` (Composer cooldown), a `laravel/vet` dev dependency or a `vet.json`
  (Vet trust gate), and dev tools in `require` instead of `require-dev` (`barryvdh/laravel-debugbar`,
  `fruitcake/laravel-debugbar`, `laravel/telescope`, `spatie/laravel-ignition`).
- Spatie checks that the app already registers itself (`Health::checks(`). They keep working: the package keeps its
  own list and its own heartbeat cache keys.
- Trusted proxies: `bootstrap/app.php` (`trustProxies`) or `app/Http/Middleware/TrustProxies.php`. Note `'*'`: a
  client can then avoid the per-IP limit with `X-Forwarded-For` (the global limit still applies).
- The deploy: `.github/workflows/*deploy*`, deploy scripts, and an `.env` template that the deploy renders (for
  example `.github/templates/.env.j2`). Note whether it runs `composer install --no-dev`, and whether it runs
  `php artisan queue:restart` after `cache:clear`.
- The project's Composer scripts (SciFY convention: `lint:check`, `lint:fix`, `analyse`, `test`) and test runner.

## Phase 2: Confirm the production values

The script shows local values. Production often differs (an app can use `smtp` locally and Mailgun in production).
Ask ONE combined `AskUserQuestion`, with the detected values as the first option of each question:

1. Production mail driver (`MAIL_MAILER`): the detected value, `mailgun`, `smtp`, or "no mail".
2. Production queue: the detected connection and its driver, "sync / no worker", or another driver.
3. Production cache store and session driver (they decide the `redis` check).
4. Does production run `schedule:run` from cron (or `schedule:work` under Supervisor)? Yes, "Not yet, add it", or
   "No scheduler at all".

If the answer to 1 is `mailgun`, ask a second `AskUserQuestion` with two questions:

- Key type: account API key (can read the domains API: the default probe) or domain sending key (needs
  `HEALTH_MAILGUN_PROBE=smtp` and SMTP credentials as secrets).
- Is Mailgun IP Access Management on? If yes, `mail` reports `failed` locally and in staging. That is expected.

Do not ask again for values these answers already cover.

## Phase 3: Decide the overrides

Compare the answers with the package's `'auto'` rules (its README, "Checks"). In production, `'auto'` reads the real
config, so most apps need no override at all. Set an override only for these cases:

| Case | Override |
|---|---|
| "No scheduler at all" | `checks.schedule => false`. The package then turns `queue` off too, because the scheduler dispatches its heartbeat. |
| Mailgun domain sending key | `HEALTH_MAILGUN_PROBE=smtp`, `HEALTH_MAILGUN_SMTP_USERNAME`, `HEALTH_MAILGUN_SMTP_PASSWORD` (env, no config change). Match `HEALTH_MAILGUN_SMTP_HOST` to the region of `services.mailgun.endpoint`. |
| A DSN host that is not GlitchTip or self-hosted Sentry (for example sentry.io) | Keep `'auto'`, but tell the developer that the package's error tracker probe is tested with GlitchTip only. |
| A service that the app depends on and the package does not check | Offer an `extra_checks` entry (a probe with a severity), following the package README, "Adding checks". Do not invent one. |

Without an override, the app needs no `config/health-endpoint.php`.

## Phase 4: Plan

Report:

1. Versions, the command prefix, and the package constraint (`^0.1` until 1.0, then `^1.0`).
2. The checks that the package will register in production, from the Phase 2 answers, and the overrides with their
   evidence.
3. Every file to create, change or delete (including an own implementation from Phase 1).
4. The supply-chain gates that the install will meet (cooldown, Vet).
5. The deploy changes, and the warnings: `TrustProxies` with `'*'`, Mailgun sending key or IP allowlist, dev tools in
   `require`, a deploy without `--no-dev`, a deploy without `queue:restart` (or with it before `cache:clear`).
6. The production steps for later (Phase 8).

**In audit mode, stop here.**

## Phase 5: Confirm

Use `AskUserQuestion` to confirm the plan. If the current branch is the default branch, also ask whether to create
`feature/health-endpoint` first. Do not continue without a clear yes.

## Phase 6: Apply

1. **Install the package.**

   ```bash
   composer require "scify/laravel-health-endpoint:^0.1"
   ```

   Two gates can stop it. Report each one and ask; never work around it:
   - **Composer cooldown** ("still in the cooldown period"): a release younger than the cooldown is withheld. Offer a
     one-off `composer require "scify/laravel-health-endpoint:^0.1" --no-cooldown`, which skips only the cooldown.
     Never write `--no-cooldown` into a script or a CI file.
   - **Laravel Vet** ("package is not trusted"): the review opens only when Composer runs interactively in a
     terminal. Ask the developer to run `composer install` (or `ddev composer install`) in their own terminal and
     trust the package there. Do not suggest `./vendor/bin/vet scify/laravel-health-endpoint`: it audits only what is
     already in `vendor/`, and the blocked package is not there. The trust entry in `vet.json` belongs in the commit.

   Then confirm the route and the heartbeats:

   ```bash
   php artisan route:list --path=health
   php artisan schedule:list
   ```

   `GET /health` (name `health-endpoint`) must exist, and the schedule must list `health-endpoint:schedule-heartbeat`
   (and `health-endpoint:queue-heartbeat` when the app has a queue worker).
2. **Overrides.** Only when Phase 3 found one: publish the config, then change only those keys.

   ```bash
   php artisan vendor:publish --tag=health-endpoint-config
   ```

3. **Remove an own implementation** when Phase 1 found one and the developer agreed: its route file and route
   registration, controller, middleware, provider (and its line in `bootstrap/providers.php`), rate limiters, Spatie
   heartbeat schedules that existed only for it, its config keys, and its tests. Remove imports that become unused.
4. **Environment.** Add to `.env.example`, with a comment:

   ```dotenv
   # /health endpoint. Empty token disables the endpoint (404).
   HEALTH_TOKEN=
   HEALTH_EXPECTED_ENV=production
   ```

   For the Mailgun `smtp` probe, also `HEALTH_MAILGUN_PROBE=smtp`, `HEALTH_MAILGUN_SMTP_USERNAME=` and
   `HEALTH_MAILGUN_SMTP_PASSWORD=`. Never write a real token into a tracked file.
5. **Deploy.** Follow [references/production.md](references/production.md), "Deploy". Ask before each change. The
   developer creates the secret; do not.
6. **App test.** Add one feature test of the installed endpoint, as in
   [references/app-test.md](references/app-test.md), in the project's test style. The package has its own test suite;
   do not copy it.
7. **Docs.** Add the section from [references/production.md](references/production.md), "README section", to the
   README or the deployment document, where the project keeps production steps.

## Phase 7: Verify

1. Run the project's checks and tests, for example `composer lint:check` and `composer test`. All must pass.
2. Call the endpoint locally. The app reads the token from the local `.env`: a token set only in the shell does not
   reach it. If the local `.env` has no `HEALTH_TOKEN`, ask before you add a random one (`.env` is untracked). If
   `bootstrap/cache/config.php` exists, run `php artisan config:clear` first. With DDEV or Sail, use their URL as
   `BASE` and skip `artisan serve`.

   ```bash
   HEALTH_TOKEN="$(grep -m1 '^HEALTH_TOKEN=' .env | cut -d= -f2- | tr -d '"')"
   BASE=http://127.0.0.1:8765
   php artisan serve --port=8765
   ```

   Run `php artisan serve` in the background. Then:

   ```bash
   curl -sS -o /dev/null -w '%{http_code}\n' "$BASE/health"
   curl -sS -w '%{stderr}HTTP %{http_code}\n' -H "X-Health-Token: $HEALTH_TOKEN" "$BASE/health" > health.local.json
   ```

   Expect 404 without the token, and 200 or 503 with it. Stop the server afterwards. Locally, `schedule` and `queue`
   report "No heartbeat yet" until `php artisan schedule:run` has run (and a worker for `queue`); a local mail
   server or a Mailgun IP allowlist can make `mail` fail. These are local, not package, failures.
3. Validate the body against the package's schema if a validator is available. Do not install one without asking:

   ```bash
   python3 -c 'import json,sys,jsonschema; jsonschema.validate(json.load(open(sys.argv[1])), json.load(open(sys.argv[2]))); print("schema ok")' health.local.json vendor/scify/laravel-health-endpoint/resources/schema/health-response.schema.json
   rm health.local.json
   ```

4. When the app replaces an own implementation: compare the old and the new response in the same environment
   (record the old one before Phase 6, step 3). Expect the same check names, order, statuses and `meta` keys.

## Phase 8: Hand over

Report:

- The checks that production will register, the overrides, and the files created, changed and deleted.
- The check, test and schema results.
- The warnings from Phase 4 that still apply.
- The production steps from [references/production.md](references/production.md), "After the deploy", in order.
- The current branch. Do not commit, push or open a pull request unless the developer asks.
