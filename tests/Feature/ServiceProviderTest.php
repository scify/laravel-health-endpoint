<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use SciFY\LaravelHealthEndpoint\CheckRegistry;
use SciFY\LaravelHealthEndpoint\Exceptions\InvalidHealthEndpointConfig;
use SciFY\LaravelHealthEndpoint\Http\Middleware\VerifyHealthToken;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Jobs\HealthQueueJob;

/**
 * Resolves a fresh Schedule, as schedule:run does, so the provider's callback runs with the current config.
 *
 * @return list<string>
 */
function scheduledCommands(): array {
    app()->forgetInstance(Schedule::class);
    $schedule = app()->make(Schedule::class);

    return array_values(array_map(fn (Event $event): string => (string) $event->command, $schedule->events()));
}

/**
 * Reloads the package route file into an empty route collection, as a boot with the current config would.
 */
function reloadPackageRoutes(): void {
    resolve(Router::class)->setRoutes(new RouteCollection);
    require __DIR__ . '/../../routes/health-endpoint.php';
    resolve(Router::class)->getRoutes()->refreshNameLookups();
}

describe('HealthEndpointServiceProvider', function (): void {
    beforeEach(function (): void {
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database', ['driver' => 'database', 'queue' => 'default']);
    });

    describe('route', function (): void {
        it('registers the route outside every middleware group, with the limiters around the token check', function (): void {
            // Act
            $route = Route::getRoutes()->getByName('health-endpoint');

            // Assert
            expect($route?->uri())->toBe('health')
                ->and($route?->gatherMiddleware())->toBe([
                    'throttle:health-endpoint.per-ip',
                    VerifyHealthToken::class,
                    'throttle:health-endpoint.global',
                ]);
        });

        it('follows the configured path', function (): void {
            // Arrange
            config()->set('health-endpoint.path', 'internal/health');

            // Act
            reloadPackageRoutes();

            // Assert
            expect(Route::getRoutes()->getByName('health-endpoint')?->uri())->toBe('internal/health');
        });

        it('registers no route when the endpoint is disabled', function (): void {
            // Arrange
            config()->set('health-endpoint.enabled', false);

            // Act
            reloadPackageRoutes();

            // Assert
            expect(Route::getRoutes()->getByName('health-endpoint'))->toBeNull();
        });

        it('works with route:cache', function (): void {
            // Act + Assert
            $this->artisan('route:cache')->assertSuccessful();
            $this->artisan('route:clear')->assertSuccessful();
        });

        it('keeps the rest of the app working when the health config is invalid', function (): void {
            // Arrange: the check list is built only for /health and the heartbeats, never at boot.
            config()->set('health-endpoint.checks.mail', 'yes');
            Route::get('ping', fn (): string => 'pong');

            // Act + Assert
            $this->get('ping')->assertOk()->assertSee('pong');
        });
    });

    describe('heartbeats', function (): void {
        it("writes the schedule heartbeat under the package key, not Spatie's", function (): void {
            // Arrange
            $this->travelTo(now()->startOfMinute());

            // Act
            $this->artisan('health-endpoint:schedule-heartbeat')->assertSuccessful();

            // Assert
            expect(cache()->get(CheckRegistry::SCHEDULE_CACHE_KEY))->toBe(now()->timestamp)
                ->and(cache()->get('health:checks:schedule:latestHeartbeatAt'))->toBeNull();
        });

        it('does nothing, successfully, when the schedule check is off', function (): void {
            // Arrange
            config()->set('health-endpoint.checks.schedule', false);

            // Act
            $this->artisan('health-endpoint:schedule-heartbeat')->assertSuccessful();

            // Assert
            expect(cache()->get(CheckRegistry::SCHEDULE_CACHE_KEY))->toBeNull();
        });

        it('dispatches one queue heartbeat job for each queue of the queue check', function (): void {
            // Arrange
            Bus::fake();

            // Act
            $this->artisan('health-endpoint:queue-heartbeat')->assertSuccessful();

            // Assert
            // The next test proves the cache key through the job's handle().
            Bus::assertDispatchedTimes(HealthQueueJob::class, 1);
            Bus::assertDispatched(HealthQueueJob::class, fn (HealthQueueJob $job): bool => $job->queue === 'default');
        });

        it('dispatches nothing when the app has no queue worker', function (): void {
            // Arrange
            Bus::fake();
            config()->set('queue.connections.database.driver', 'sync');

            // Act
            $this->artisan('health-endpoint:queue-heartbeat')->assertSuccessful();

            // Assert
            Bus::assertNotDispatched(HealthQueueJob::class);
        });

        it('lets the worker write the queue heartbeat under the package key', function (): void {
            // Arrange
            $this->travelTo(now()->startOfMinute());
            $check = resolve(CheckRegistry::class)->queueCheck();
            expect($check)->toBeInstanceOf(QueueCheck::class);

            // Act
            (new HealthQueueJob($check))->onQueue('default')->handle();

            // Assert
            expect(cache()->get(CheckRegistry::QUEUE_CACHE_KEY . '.default'))->toBe(now()->timestamp);
        });
    });

    describe('schedule', function (): void {
        it('schedules both heartbeats every minute', function (): void {
            // Act
            $commands = scheduledCommands();

            // Assert
            expect($commands)->toHaveCount(2)
                ->and($commands[0])->toContain('health-endpoint:schedule-heartbeat')
                ->and($commands[1])->toContain('health-endpoint:queue-heartbeat');
            expect(collect(resolve(Schedule::class)->events())->every(fn (Event $event): bool => $event->expression === '* * * * *'))->toBeTrue();
        });

        it('schedules only the schedule heartbeat for an app without a queue worker', function (): void {
            // Arrange
            config()->set('queue.connections.database.driver', 'sync');

            // Act
            $commands = scheduledCommands();

            // Assert
            expect($commands)->toHaveCount(1)
                ->and($commands[0])->toContain('health-endpoint:schedule-heartbeat');
        });

        it('schedules nothing when the endpoint is disabled or the app has no cron', function (string $key): void {
            // Arrange
            config()->set($key, false);

            // Act + Assert
            expect(scheduledCommands())->toBe([]);
        })->with(['health-endpoint.enabled', 'health-endpoint.checks.schedule']);

        it("reports an invalid config instead of stopping the app's other scheduled tasks", function (): void {
            // Arrange
            Exceptions::fake();
            config()->set('health-endpoint.checks.mail', 'yes');

            // Act
            $commands = scheduledCommands();

            // Assert
            expect($commands)->toBe([]);
            Exceptions::assertReported(InvalidHealthEndpointConfig::class);
        });
    });
});
