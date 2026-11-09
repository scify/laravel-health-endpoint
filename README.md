# Laravel Health Endpoint

A token-protected, rate-limited `GET /health` endpoint for Laravel 12 and 13, built on
[spatie/laravel-health](https://github.com/spatie/laravel-health). It returns a JSON schema, with only the checks that the app uses.

- Checks run live on each authorised request. No result store, no notifications.
- The checks are selected from the app's configuration: an app without mail gets no `mail` check.
- Mail and error tracker checks prove that the credentials work, without sending a message or an event.
- New services need one small probe class (see [Adding checks](#adding-checks)).

Status: `0.x`. The contract (`schema_version` 1.0) is stable; the PHP API and the config can still change before `1.0.0`.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [The response](#the-response)
- [Checks](#checks)
- [Configuration](#configuration)
- [Production setup](#production-setup)
- [Adding checks](#adding-checks)
- [Security](#security)
- [Development](#development)
- [Licence](#licence)

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- `spatie/laravel-health` 1.40 or later (installed as a dependency)

## Installation

```bash
composer require scify/laravel-health-endpoint
```

The service provider is auto-discovered. Generate a token and add it to `.env`:

```bash
openssl rand -hex 32
```

```dotenv
HEALTH_TOKEN=<the generated value>
HEALTH_EXPECTED_ENV=production
```

An empty `HEALTH_TOKEN` disables the endpoint: every request gets 404. Use one token per app and per environment, and
store the production token in your deployment's secret store.

Call the endpoint:

```bash
curl -sS -H "X-Health-Token: $HEALTH_TOKEN" https://example.org/health
```

Publish the config only to change a value:

```bash
php artisan vendor:publish --tag=health-endpoint-config
```

## The response

```json
{
  "schema_version": "1.0",
  "status": "ok",
  "checked_at": "2026-10-12T06:51:19Z",
  "duration_ms": 1284,
  "app": { "name": "Example", "environment": "production" },
  "checks": [
    { "name": "database", "status": "ok", "summary": "Ok",        "duration_ms": 2,   "meta": { "connection_name": "mysql" } },
    { "name": "mail",     "status": "ok", "summary": "Connected", "duration_ms": 853, "meta": { "driver": "mailgun" } },
    { "name": "disk",     "status": "ok", "summary": "59%",       "duration_ms": 3,   "meta": { "disk_space_used_percentage": 59 } }
  ]
}
```

| HTTP status | Meaning |
|---|---|
| 200 | `status` is `ok` or `warning` |
| 503 | `status` is `failed`: a check is `failed` or `crashed` |
| 404 | missing, wrong or unconfigured token |
| 429 | rate limit; see `Retry-After` |

Treat any other status, including 500 (for example an invalid config), as down.

- `checks` lists only the checks that the app registers. An absent check means "not applicable".
- Each check `status` is `ok`, `warning`, `failed`, `crashed` or `skipped`. `skipped` does not change the overall status.
- `summary` is for humans. Monitors must not parse it.
- `meta` is always an object and contains only allow-listed keys.
- The response contains no exception messages, hostnames, credentials or versions.

The JSON Schema is [resources/schema/health-response.schema.json](resources/schema/health-response.schema.json).
The package's major version follows the contract's major version.

## Checks

| Name | What it proves | `'auto'` registers it when |
|---|---|---|
| `database` | The default connection answers | `database.default` is set |
| `cache` | The cache store can write and read | always (`true`) |
| `mail` | The mail provider is reachable and accepts the credentials. No message is sent. | the transport of `mail.default` is not `log` or `array` |
| `queue` | A worker processed the heartbeat job recently | the driver of the default queue connection is not `sync` or `null` |
| `schedule` | `schedule:run` ran in the last 2 minutes | always (`true`); set `false` without cron |
| `disk` | Used space of `storage_path()`: warning above 70 %, failure above 90 % | `proc_open` exists |
| `debug_mode` | `APP_DEBUG` is off | always (`true`) |
| `environment` | The app runs in `HEALTH_EXPECTED_ENV` | always (`true`) |
| `redis` | Redis answers | the cache store, the session or the default queue connection uses Redis |
| `error_tracker` | The Sentry DSN is accepted (GlitchTip or self-hosted Sentry). Warning only. No event is sent. | `sentry.dsn` is set |

**Mail probes.**

| Transport | Probe |
|---|---|
| `smtp` | The configured transport: connect, EHLO, STARTTLS, AUTH, QUIT |
| `mailgun` | `GET /v3/domains/{domain}` with the API key. Mailgun answers 401 both for a bad key and for an IP outside its allowlist. |
| `mailgun` with `HEALTH_MAILGUN_PROBE=smtp` | An SMTP handshake with `HEALTH_MAILGUN_SMTP_USERNAME` and `HEALTH_MAILGUN_SMTP_PASSWORD`, for domain sending keys that cannot read the domains API |
| anything else | `skipped` ("Driver not supported") |

**Error tracker probe.** The check sends an empty envelope (`{}`) to the DSN's project, with the key in the
`X-Sentry-Auth` header. The ingest service checks the key first, and an envelope without items creates no event and
uses no quota. GlitchTip accepts a disabled key, so the probe cannot detect one. Tested live against GlitchTip on
2026-11-09: a valid key got 200, an unknown key got 403, and GlitchTip recorded no event. Not yet tested with sentry.io.

## Configuration

Each check takes `'auto'`, `true` or `false` in `checks`. A check that a published config does not name uses the
package default; a check added in a later release defaults to `false`.

```php
'checks' => [
    'schedule' => false,       // no cron in production: also turns off 'queue'
    'error_tracker' => true,   // always, even if auto would leave it out
],
```

| Env variable | Default | Purpose |
|---|---|---|
| `HEALTH_TOKEN` | empty | The shared secret. Empty disables the endpoint. |
| `HEALTH_EXPECTED_ENV` | `production` | The environment the `environment` check expects |
| `HEALTH_ENDPOINT_ENABLED` | `true` | `false` removes the route and the heartbeat schedules |
| `HEALTH_MAILGUN_PROBE` | `api` | `smtp` for Mailgun domain sending keys |
| `HEALTH_MAILGUN_SMTP_HOST` | `smtp.eu.mailgun.org` | Match the region of `services.mailgun.endpoint` |
| `HEALTH_MAILGUN_SMTP_USERNAME`, `HEALTH_MAILGUN_SMTP_PASSWORD` | empty | Only for the `smtp` probe |

The config file also sets the path (`health`), the rate limits, the disk thresholds, the timeouts and the cache store of
the heartbeats. See [config/health-endpoint.php](config/health-endpoint.php).

An invalid config (for example an unknown check name) never stops the app: the check list is built only for `/health`
and the heartbeats. `/health` then answers 500, and the scheduler reports the error and skips only the heartbeats.

## Production setup

1. **Token.** Store `HEALTH_TOKEN` in your deployment's secret store and write it to `.env` on deploy.
2. **Cron.** The `schedule` and `queue` checks need `schedule:run` every minute. Add it to the crontab of the user that
   runs PHP-FPM and the queue worker (often `www-data`), not root or a personal account; otherwise files that it
   creates in `storage/` become unwritable for the app.

   ```cron
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```

   The package schedules its own two heartbeat commands; `routes/console.php` needs nothing.
3. **Queue worker.** For the `queue` check, a worker must process the default queue. Run `php artisan queue:restart`
   on deploy, after `cache:clear`, so the worker loads the new code.
4. **After a deploy**, `schedule` and `queue` can report `No heartbeat yet` for up to 2 minutes. Configure the monitor
   to alert only after 2 consecutive failures.
5. **Version banner.** Set `expose_php = Off` in the PHP-FPM `php.ini`, so responses carry no `X-Powered-By` header.

The heartbeats use their own cache keys (`health-endpoint:…`), so they never collide with checks that the app
registers with Spatie's package itself. Two apps that share one Redis database need different cache prefixes; Laravel's
default prefix contains the app name.

## Adding checks

Most checks ask one external service whether it answers and accepts the credentials. Write a probe:

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use SciFY\LaravelHealthEndpoint\Probes\HttpServiceProbe;
use SciFY\LaravelHealthEndpoint\Probes\Probe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;

final class PaymentGatewayProbe extends HttpServiceProbe implements Probe
{
    public function run(): ProbeResult
    {
        return $this->send();
    }

    protected function request(PendingRequest $http): Response
    {
        return $http->withToken(config('services.payments.key'))->get('https://api.payments.example/v1/account');
    }
}
```

`HttpServiceProbe` sets the timeout (5 s), catches connection errors and maps the status: 2xx is `ok`, 401 and 403 are
"Rejected", everything else is "Unreachable". Override `classify()` for another mapping, and `timeoutSeconds()` for
another timeout.

Register it in `extra_checks`, with a severity:

```php
'extra_checks' => [
    'payment_gateway' => ['probe' => App\Health\PaymentGatewayProbe::class, 'severity' => 'failed'],
],
```

Use `failed` only when users are affected; otherwise `warning`. For logic that is not one request to one service,
register a full Spatie check class instead, with the `meta` keys that may reach the response:

```php
'search_index' => ['class' => App\Health\SearchIndexCheck::class, 'meta' => ['documents']],
```

The key is the check's name in the response: snake_case, and not one of the shared names. A new mail provider is a
class that implements `MailProbe`, added to `options.mail.probes` under its transport name.

To add a check or probe to this package, follow the checklist in [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

- The token goes in the `X-Health-Token` header, never in the URL. It is compared with `hash_equals`.
- Every token failure answers 404 with an empty `text/plain` body, so the route stays invisible to scanners.
- Two rate limiters: 10 requests per minute per IP **before** the token check, and 30 check runs per minute in total
  **after** it. Requests without a valid token cannot use up the run limit and lock out the monitor.
- The route is outside the `web` and `api` groups: no session, no cookies, no CSRF.
- The limiters use the default cache store. A deploy with several servers needs a shared store.
- If your app trusts all proxies (`TrustProxies` with `'*'`), a client can change its IP with `X-Forwarded-For` and
  avoid the per-IP limit. The global limit still applies.

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Development

```bash
composer setup           # dependencies, Gitleaks and the pre-commit hook
composer test            # Pest
composer test:coverage   # Pest with coverage (needs Xdebug)
composer analyse         # PHPStan, level 9
composer lint:check      # Pint, Rector and PHPStan, without changes
composer lint:fix        # Rector and Pint fix the code, then PHPStan
```

`composer setup` installs a pinned Gitleaks in `tools/bin/` after it checks the release checksum, and sets
`core.hooksPath` to `.githooks/`. The pre-commit hook scans the staged changes for secrets and checks the staged PHP
files. It changes no file: run `composer lint:fix` when it reports style findings.

## Licence

MIT. See [LICENSE.md](LICENSE.md). Built on [spatie/laravel-health](https://github.com/spatie/laravel-health) (MIT).
