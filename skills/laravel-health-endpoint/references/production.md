# Production steps for `/health`

The package does the route, the checks and the heartbeat schedules. Production still needs a secret, a running
scheduler and worker, and a deploy that loads new code. This file lists those steps.

## Deploy

Ask before each change. Change only the deploy files that the repository already has.

1. **Token secret.** If the deploy renders `.env` from a template (for example `.github/templates/.env.j2` with
   `cuchi/jinja2-action`), add `HEALTH_TOKEN={{ HEALTH_TOKEN }}` to the template and pass the secret in the workflow:

   ```yaml
   variables: |
     HEALTH_TOKEN=${{ secrets.HEALTH_TOKEN }}
   ```

   Change both files in the same commit: a renderer in strict mode (`strict: true`) fails when the template has the
   placeholder and the workflow does not pass the variable. Set `HEALTH_EXPECTED_ENV` as a literal per environment
   (`production`, `staging`), not as a secret.
2. **Worker restart.** If production runs a queue worker and the deploy has no `php artisan queue:restart`, add it
   after `cache:clear`. The restart signal lives in the cache, so a `cache:clear` after it deletes the signal, and the
   worker keeps the old code.
3. **Dependencies.** The deploy should run `composer install --no-dev`. Report it when it does not; do not change it
   without asking.

## README section

Add this to the README or the deployment document. Replace `<app-url>`, `<app-path>`, `<php-binary>` and `<app-user>`,
and remove the steps that do not apply.

````markdown
## Health endpoint

`GET /health` runs the dependency checks live and returns JSON for the SciFY monitoring app. It comes from the package
[scify/laravel-health-endpoint](https://github.com/scify/laravel-health-endpoint), whose README lists the checks and
options. `/up` stays the unauthenticated liveness probe.

1. Generate a token and store it as the `HEALTH_TOKEN` secret of the deploy. An empty token disables the endpoint.

   ```bash
   openssl rand -hex 32
   ```

2. The monitor sends the token in a header, never in the URL:

   ```bash
   curl -sS -H "X-Health-Token: $HEALTH_TOKEN" https://<app-url>/health
   ```

   HTTP 200 means `ok` or `warning`; HTTP 503 means `failed`. Requests without a valid token get 404.

3. The `schedule` and `queue` checks need the scheduler and a queue worker. Run the scheduler every minute in the
   crontab of the user that runs PHP-FPM and the worker (`<app-user>`), not root or a personal account:

   ```cron
   * * * * * cd <app-path> && <php-binary> artisan schedule:run >> /dev/null 2>&1
   ```

   The package schedules its own heartbeat commands. Right after a deploy, both checks can report `No heartbeat yet`
   for up to 2 minutes.

4. Set `expose_php = Off` in the PHP-FPM `php.ini`, so responses carry no `X-Powered-By` header.
````

## After the deploy

Give the developer these steps, in order:

1. Generate the token (`openssl rand -hex 32`) and store it as a secret, one per environment.
2. Deploy.
3. Add the cron entry for `schedule:run`, as the user of PHP-FPM and the worker, if it does not exist. The cron user
   matters: files that root creates in `storage/` (for example the daily log) become unwritable for the app.
4. Check the worker when the app has one (`sudo supervisorctl status`).
5. Call `/health` with the token. `schedule` and `queue` turn `ok` within about 2 minutes.
6. Check the version banner on the real URL. `X-Powered-By` is expected locally, not in production:

   ```bash
   curl -sI "$HEALTH_URL" -H "X-Health-Token: $HEALTH_TOKEN" | grep -i -E '^(x-powered-by|server):'
   ```

7. Give the monitor the URL and the token. It should alert only after 2 consecutive failures, and it can store the
   expected list of check names to notice a check that disappears.
