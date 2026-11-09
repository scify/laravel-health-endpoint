# The app's own `/health` test

The package tests the endpoint itself. The app needs one small feature test for its own side: the package is
installed and routed, and the app's production drivers select the expected checks. Adapt it to the project's test
style (`tests/README.md`), naming and static analysis level.

The third test is the important one. Fill its arrange block with the production values from Phase 2 and its
expected list with the checks that the package will register for them, in the package's order: `database`, `cache`,
`mail`, `queue`, `schedule`, `disk`, `debug_mode`, `environment`, `redis`, `error_tracker`, then `extra_checks` in
config order. Leave out the checks that production does not use.

```php
<?php

declare(strict_types=1);

use SciFY\LaravelHealthEndpoint\CheckRegistry;
use Spatie\Health\Checks\Check;

/**
 * The endpoint comes from scify/laravel-health-endpoint, which has its own test suite.
 * These tests cover the app's side: the package is installed, and the production drivers select the expected checks.
 */
describe('GET /health', function (): void {
    beforeEach(function (): void {
        $this->token = str_repeat('a1', 32);
        config()->set('health-endpoint.token', $this->token);
    });

    it('returns 404 with an empty body without the token', function (): void {
        // Act
        $response = $this->get('/health');

        // Assert
        $response->assertNotFound();

        expect($response->getContent())->toBe('');
    });

    it('returns the shared contract for a valid token', function (): void {
        // Act
        $response = $this->get('/health', ['X-Health-Token' => $this->token]);

        // Assert
        expect($response->status())->toBeIn([200, 503]);
        $response->assertJsonPath('schema_version', '1.0')
            ->assertJsonPath('app.name', config('app.name'))
            ->assertJsonStructure(['status', 'checked_at', 'duration_ms', 'checks' => [['name', 'status', 'summary', 'duration_ms', 'meta']]]);
    });

    it('selects the checks of production, in order', function (): void {
        // Arrange: the production drivers from Phase 2. Example: Mailgun, database queue, Redis cache,
        // database sessions, and a GlitchTip DSN.
        config()->set('mail.default', 'mailgun');
        config()->set('mail.mailers.mailgun.transport', 'mailgun');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');
        config()->set('cache.default', 'redis');
        config()->set('cache.stores.redis.driver', 'redis');
        config()->set('session.driver', 'database');
        config()->set('sentry.dsn', 'https://publickey@glitchtip.example.org/3');

        // Act
        $names = array_map(fn (Check $check): string => $check->getName(), resolve(CheckRegistry::class)->checks());

        // Assert
        expect($names)->toBe([
            'database', 'cache', 'mail', 'queue', 'schedule', 'disk', 'debug_mode', 'environment', 'redis', 'error_tracker',
        ]);
    });
});
```

With PHPUnit instead of Pest, write the same three cases as `test_…` methods. A feature test with `RefreshDatabase`
fits most SciFY apps; the first two cases need the database only for the `database` check.
