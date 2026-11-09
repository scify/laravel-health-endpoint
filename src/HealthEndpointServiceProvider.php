<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SciFY\LaravelHealthEndpoint\Commands\QueueHeartbeatCommand;
use SciFY\LaravelHealthEndpoint\Commands\ScheduleHeartbeatCommand;
use SciFY\LaravelHealthEndpoint\Exceptions\InvalidHealthEndpointConfig;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class HealthEndpointServiceProvider extends PackageServiceProvider {
    public function configurePackage(Package $package): void {
        $package
            ->name('laravel-health-endpoint')
            ->hasConfigFile('health-endpoint')
            ->hasRoute('health-endpoint')
            ->hasCommands([ScheduleHeartbeatCommand::class, QueueHeartbeatCommand::class]);
    }

    public function packageBooted(): void {
        RateLimiter::for('health-endpoint.per-ip', fn (Request $request): Limit => Limit::perMinute(config()->integer('health-endpoint.rate_limits.per_ip', 10))
            ->by($request->ip() ?? 'unknown'));
        RateLimiter::for('health-endpoint.global', fn (): Limit => Limit::perMinute(config()->integer('health-endpoint.rate_limits.global', 30))
            ->by('global'));

        $this->callAfterResolving(Schedule::class, $this->scheduleHeartbeats(...));
    }

    /**
     * Only for the checks that are registered, so an app without a queue gets no queue heartbeat job.
     */
    private function scheduleHeartbeats(Schedule $schedule): void {
        if (! config()->boolean('health-endpoint.enabled', true)) {
            return;
        }

        $registry = $this->app->make(CheckRegistry::class);

        try {
            $hasSchedule = $registry->scheduleCheck() !== null;
            $hasQueue = $registry->queueCheck() !== null;
        } catch (InvalidHealthEndpointConfig $invalidHealthEndpointConfig) {
            // An invalid health config must not stop the app's other scheduled tasks. /health reports it as a 500.
            report($invalidHealthEndpointConfig);

            return;
        }

        if ($hasSchedule) {
            $schedule->command(ScheduleHeartbeatCommand::class)->everyMinute();
        }

        if ($hasQueue) {
            $schedule->command(QueueHeartbeatCommand::class)->everyMinute();
        }
    }
}
