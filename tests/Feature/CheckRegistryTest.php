<?php

declare(strict_types=1);

use SciFY\LaravelHealthEndpoint\CheckRegistry;
use SciFY\LaravelHealthEndpoint\Checks\ServiceCheck;
use SciFY\LaravelHealthEndpoint\Exceptions\InvalidHealthEndpointConfig;
use SciFY\LaravelHealthEndpoint\Tests\Fixtures\ScriptedCheck;
use SciFY\LaravelHealthEndpoint\Tests\Fixtures\ScriptedProbe;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\ScheduleCheck;

/**
 * @return list<string>
 */
function registeredNames(): array {
    return array_map(fn (Check $check): string => $check->getName(), resolve(CheckRegistry::class)->checks());
}

function registeredCheck(string $name): Check {
    $check = collect(resolve(CheckRegistry::class)->checks())->first(fn (Check $check): bool => $check->getName() === $name);

    expect($check)->toBeInstanceOf(Check::class);

    return $check;
}

describe('CheckRegistry', function (): void {
    beforeEach(function (): void {
        // An app like annotrAIn in production: Mailgun, database queue, file cache, database sessions, an error tracker.
        config()->set('mail.default', 'mailgun');
        config()->set('mail.mailers.mailgun.transport', 'mailgun');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database', ['driver' => 'database', 'queue' => 'default']);
        config()->set('cache.default', 'file');
        config()->set('session.driver', 'database');
        config()->set('sentry.dsn', 'https://publickey@glitchtip.example.org/3');
    });

    describe('auto detection', function (): void {
        it('registers the full selection in contract order', function (): void {
            expect(registeredNames())->toBe([
                'database', 'cache', 'mail', 'queue', 'schedule', 'disk', 'debug_mode', 'environment', 'error_tracker',
            ]);
        });

        it('leaves out the mail check for the log and array transports', function (string $transport): void {
            // Arrange
            config()->set('mail.default', $transport);
            config()->set("mail.mailers.{$transport}.transport", $transport);

            // Act + Assert
            expect(registeredNames())->not->toContain('mail');
        })->with(['log', 'array']);

        it('leaves out the queue check for the sync and null drivers', function (string $driver): void {
            // Arrange
            config()->set('queue.connections.database.driver', $driver);

            // Act + Assert
            expect(registeredNames())->not->toContain('queue');
        })->with(['sync', 'null']);

        it('detects the queue driver, not the connection name', function (): void {
            // Arrange: a connection named "jobs" that runs on Redis.
            config()->set('queue.default', 'jobs');
            config()->set('queue.connections.jobs', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);

            // Act
            $names = registeredNames();

            // Assert
            expect($names)->toContain('queue')
                ->and($names)->toContain('redis');
        });

        it('adds the redis check when the cache or the session uses Redis', function (string $key, string $value): void {
            // Arrange
            config()->set($key, $value);
            config()->set('cache.stores.redis', ['driver' => 'redis']);

            // Act + Assert
            expect(registeredNames())->toContain('redis');
        })->with([
            'cache' => ['cache.default', 'redis'],
            'session' => ['session.driver', 'redis'],
        ]);

        it('leaves out the error tracker check without a DSN', function (): void {
            // Arrange
            config()->set('sentry.dsn');

            // Act + Assert
            expect(registeredNames())->not->toContain('error_tracker');
        });

        it('runs the error tracker check as a warning-only service check', function (): void {
            // Arrange: the probe gets a refused connection.
            config()->set('sentry.dsn', 'https://publickey@127.0.0.1:1/3');

            // Act
            $result = registeredCheck('error_tracker')->run();

            // Assert
            expect(registeredCheck('error_tracker'))->toBeInstanceOf(ServiceCheck::class)
                ->and($result->status->value)->toBe('warning');
        });
    });

    describe('overrides', function (): void {
        it('never registers a check set to false', function (): void {
            // Arrange
            config()->set('health-endpoint.checks.mail', false);

            // Act + Assert
            expect(registeredNames())->not->toContain('mail');
        });

        it('always registers a check set to true', function (): void {
            // Arrange: no DSN, so auto would leave the check out.
            config()->set('sentry.dsn');
            config()->set('health-endpoint.checks.error_tracker', true);

            // Act + Assert
            expect(registeredNames())->toContain('error_tracker');
        });

        it('turns the queue check off with the schedule check, because the scheduler dispatches its heartbeat', function (): void {
            // Arrange
            config()->set('health-endpoint.checks.schedule', false);
            config()->set('health-endpoint.checks.queue', true);

            // Act
            $names = registeredNames();

            // Assert
            expect($names)->not->toContain('schedule')
                ->and($names)->not->toContain('queue');
        });

        it('uses the package default for a check that a published config does not name', function (): void {
            // Arrange: an app published the config before the error_tracker key existed.
            config()->set('health-endpoint.checks', ['database' => 'auto', 'cache' => true]);

            // Act + Assert
            expect(registeredNames())->toContain('error_tracker', 'mail', 'schedule');
        });
    });

    describe('options', function (): void {
        it('expects the environment from config', function (): void {
            // Arrange
            // EnvironmentCheck compares with the booted environment, which can differ from config('app.env').
            config()->set('health-endpoint.options.expected_environment', app()->environment());

            // Act
            $result = registeredCheck('environment')->run();

            // Assert
            expect($result->status->value)->toBe('ok');
        });

        it('falls back to production when the expected environment is not a string', function (): void {
            // Arrange: HEALTH_EXPECTED_ENV=null makes env() return null.
            config()->set('health-endpoint.options.expected_environment');

            // Act
            $result = registeredCheck('environment')->run();

            // Assert
            expect($result->meta['expected'])->toBe('production');
        });

        it("uses the package cache key for the schedule heartbeat, so it cannot collide with Spatie's", function (): void {
            // Act
            $check = resolve(CheckRegistry::class)->scheduleCheck();

            // Assert
            expect($check)->toBeInstanceOf(ScheduleCheck::class)
                ->and($check?->getCacheKey())->toBe('health-endpoint:schedule:latestHeartbeatAt');
        });

        it('uses the configured heartbeat cache store', function (): void {
            // Arrange
            config()->set('health-endpoint.options.heartbeat_cache_store', 'array');

            // Act
            $registry = resolve(CheckRegistry::class);

            // Assert
            expect($registry->scheduleCheck()?->getCacheStoreName())->toBe('array')
                ->and($registry->queueCheck()?->getCacheStoreName())->toBe('array')
                ->and($registry->queueCheck()?->getHeartbeatCacheKey('default'))->toBe('health-endpoint:queue:latestHeartbeatAt.default');
        });
    });

    describe('extra checks', function (): void {
        it('registers a probe and a class after the shared checks, in config order', function (): void {
            // Arrange
            withoutSharedChecks();
            config()->set('health-endpoint.extra_checks', [
                'payment_gateway' => ['probe' => ScriptedProbe::class, 'severity' => 'failed'],
                'search_index' => ['class' => ScriptedCheck::class, 'meta' => ['documents']],
            ]);

            // Act
            $checks = resolve(CheckRegistry::class)->checks();

            // Assert
            expect(array_map(fn (Check $check): string => $check->getName(), $checks))->toBe(['payment_gateway', 'search_index'])
                ->and($checks[0])->toBeInstanceOf(ServiceCheck::class)
                ->and($checks[1])->toBeInstanceOf(ScriptedCheck::class)
                ->and(resolve(CheckRegistry::class)->metaAllowList())
                ->toMatchArray(['payment_gateway' => [], 'search_index' => ['documents']]);
        });
    });

    describe('invalid config', function (): void {
        it('throws when the check list is built', function (string $key, mixed $value, string $message): void {
            // Arrange
            config()->set($key, $value);

            // Act + Assert
            expect(fn () => resolve(CheckRegistry::class)->checks())
                ->toThrow(InvalidHealthEndpointConfig::class, $message);
        })->with([
            'a value other than auto, true or false' => ['health-endpoint.checks.mail', 'yes', "[checks.mail] must be 'auto', true or false"],
            'an unknown shared check' => ['health-endpoint.checks.payments', true, '[checks.payments] is not a shared check'],
            'an extra check with a shared name' => ['health-endpoint.extra_checks.mail', ['class' => ScriptedCheck::class], 'the name is a shared check'],
            'an extra check name that is not snake_case' => ['health-endpoint.extra_checks.PaymentGateway', ['class' => ScriptedCheck::class], 'the name must be snake_case'],
            'an extra check with probe and class' => ['health-endpoint.extra_checks.payments', ['probe' => ScriptedProbe::class, 'class' => ScriptedCheck::class, 'severity' => 'failed'], 'set exactly one of [probe] or [class]'],
            'an extra check with neither' => ['health-endpoint.extra_checks.payments', ['severity' => 'failed'], 'set exactly one of [probe] or [class]'],
            'a probe without severity' => ['health-endpoint.extra_checks.payments', ['probe' => ScriptedProbe::class], "[severity] must be 'failed' or 'warning'"],
            'a probe with an unknown severity' => ['health-endpoint.extra_checks.payments', ['probe' => ScriptedProbe::class, 'severity' => 'critical'], "[severity] must be 'failed' or 'warning'"],
            'a probe with meta' => ['health-endpoint.extra_checks.payments', ['probe' => ScriptedProbe::class, 'severity' => 'failed', 'meta' => ['x']], 'a probe has no [meta]'],
            'a probe class that is not a probe' => ['health-endpoint.extra_checks.payments', ['probe' => ScriptedCheck::class, 'severity' => 'failed'], '[probe] must implement'],
            'a check class that is not a check' => ['health-endpoint.extra_checks.payments', ['class' => ScriptedProbe::class], '[class] must extend'],
            'meta that is not a list of strings' => ['health-endpoint.extra_checks.payments', ['class' => ScriptedCheck::class, 'meta' => ['a' => 1]], '[meta] must be a list of strings'],
            'a mail probe that is not a mail probe' => ['health-endpoint.options.mail.probes.ses', ScriptedProbe::class, '[options.mail.probes.ses] must implement'],
        ]);
    });
});
