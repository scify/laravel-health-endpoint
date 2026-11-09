<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint;

use SciFY\LaravelHealthEndpoint\Checks\MailTransportCheck;
use SciFY\LaravelHealthEndpoint\Checks\ServiceCheck;
use SciFY\LaravelHealthEndpoint\Checks\Severity;
use SciFY\LaravelHealthEndpoint\Exceptions\InvalidHealthEndpointConfig;
use SciFY\LaravelHealthEndpoint\Probes\MailProbe;
use SciFY\LaravelHealthEndpoint\Probes\Probe;
use SciFY\LaravelHealthEndpoint\Probes\SentryEnvelopeProbe;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;

/**
 * Builds the check list from config/health-endpoint.php. Names are the contract keys.
 *
 * Built on demand (a /health request or a heartbeat command), never at boot, so an invalid config cannot break the app.
 */
final class CheckRegistry {
    /**
     * The shared checks in response order, with the value used when the published config does not name a check.
     * A check added in a later release defaults to false, so a package update never adds a check without notice.
     */
    public const array DEFAULTS = [
        'database' => 'auto',
        'cache' => true,
        'mail' => 'auto',
        'queue' => 'auto',
        'schedule' => true,
        'disk' => 'auto',
        'debug_mode' => true,
        'environment' => true,
        'redis' => 'auto',
        'error_tracker' => 'auto',
    ];

    public const string SCHEDULE_CACHE_KEY = 'health-endpoint:schedule:latestHeartbeatAt';

    public const string QUEUE_CACHE_KEY = 'health-endpoint:queue:latestHeartbeatAt';

    /**
     * Meta keys the shared checks may expose. Every other key is dropped.
     */
    private const array SHARED_META = [
        'database' => ['connection_name'],
        'cache' => ['driver'],
        'mail' => ['driver'],
        'disk' => ['disk_space_used_percentage'],
    ];

    /**
     * @return list<Check>
     *
     * @throws InvalidHealthEndpointConfig
     */
    public function checks(): array {
        $this->validate();

        $checks = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            if ($this->isActive($name)) {
                $checks[] = $this->make($name);
            }
        }

        foreach ($this->extraChecks() as $name => $entry) {
            $checks[] = $this->makeExtra($name, $entry);
        }

        return $checks;
    }

    /**
     * @return array<string, list<string>>
     */
    public function metaAllowList(): array {
        $allowList = self::SHARED_META;
        foreach ($this->extraChecks() as $name => $entry) {
            $meta = $entry['meta'] ?? [];
            $allowList[$name] = is_array($meta) ? array_values(array_filter($meta, is_string(...))) : [];
        }

        return $allowList;
    }

    /**
     * @throws InvalidHealthEndpointConfig
     */
    public function scheduleCheck(): ?ScheduleCheck {
        $this->validate();

        return $this->isActive('schedule') ? $this->scheduleCheckInstance() : null;
    }

    /**
     * @throws InvalidHealthEndpointConfig
     */
    public function queueCheck(): ?QueueCheck {
        $this->validate();

        return $this->isActive('queue') ? $this->queueCheckInstance() : null;
    }

    private function isActive(string $name): bool {
        // The scheduler dispatches the queue heartbeat job, so the queue check cannot work without it.
        if ($name === 'queue' && ! $this->isActive('schedule')) {
            return false;
        }

        $setting = $this->setting($name);

        return $setting === 'auto' ? $this->detect($name) : $setting === true;
    }

    private function setting(string $name): mixed {
        $checks = config('health-endpoint.checks');

        return is_array($checks) && array_key_exists($name, $checks) ? $checks[$name] : self::DEFAULTS[$name];
    }

    /**
     * The 'auto' rules. Drivers are read as drivers, never as connection or store names.
     */
    private function detect(string $name): bool {
        return match ($name) {
            'database' => filled(config('database.default')),
            'mail' => ! in_array($this->mailTransport(), ['log', 'array', null], true),
            // "sync" runs jobs inline and "null" discards them: neither has a worker that could send a heartbeat.
            'queue' => ! in_array($this->queueDriver(), ['sync', 'null', null], true),
            // UsedDiskSpaceCheck runs df through proc_open; disable_functions removes the function.
            'disk' => function_exists('proc_open'),
            'redis' => in_array('redis', [$this->cacheDriver(), config('session.driver'), $this->queueDriver()], true),
            'error_tracker' => filled(config('sentry.dsn')),
            default => true,
        };
    }

    private function make(string $name): Check {
        return match ($name) {
            'database' => DatabaseCheck::new()->name('database'),
            'cache' => CacheCheck::new()->name('cache'),
            'mail' => MailTransportCheck::new()->name('mail'),
            'queue' => $this->queueCheckInstance(),
            'schedule' => $this->scheduleCheckInstance(),
            'disk' => UsedDiskSpaceCheck::new()->name('disk')
                // The default "." is the PHP working directory (public/ under PHP-FPM).
                ->filesystemName($this->option('disk.path', storage_path()))
                ->warnWhenUsedSpaceIsAbovePercentage(config()->integer('health-endpoint.options.disk.warn_above', 70))
                ->failWhenUsedSpaceIsAbovePercentage(config()->integer('health-endpoint.options.disk.fail_above', 90)),
            'debug_mode' => DebugModeCheck::new()->name('debug_mode'),
            'environment' => EnvironmentCheck::new()->name('environment')
                ->expectEnvironment($this->option('expected_environment', 'production')),
            'redis' => RedisCheck::new()->name('redis'),
            'error_tracker' => ServiceCheck::new()->name('error_tracker')
                ->probe(resolve(SentryEnvelopeProbe::class))
                // A tracker outage does not affect users.
                ->severity(Severity::Warning),
            default => throw InvalidHealthEndpointConfig::because("unknown check [{$name}]"),
        };
    }

    private function scheduleCheckInstance(): ScheduleCheck {
        $check = ScheduleCheck::new()->name('schedule')
            ->cacheKey(self::SCHEDULE_CACHE_KEY)
            // Spatie's default max age is 1 minute with a strict ">", which flaps when schedule:run starts late.
            ->heartbeatMaxAgeInMinutes(config()->integer('health-endpoint.options.schedule_max_age_minutes', 2));

        $store = $this->heartbeatStore();

        return $store === null ? $check : $check->useCacheStore($store);
    }

    private function queueCheckInstance(): QueueCheck {
        $check = QueueCheck::new()->name('queue')->cacheKey(self::QUEUE_CACHE_KEY);
        $store = $this->heartbeatStore();

        if ($store !== null) {
            $check->useCacheStore($store);
        }

        return $check;
    }

    /**
     * @param  array<mixed>  $entry  Validated by validateExtraChecks().
     */
    private function makeExtra(string $name, array $entry): Check {
        $probe = $entry['probe'] ?? null;
        $severity = $entry['severity'] ?? null;
        if (is_string($probe) && is_a($probe, Probe::class, true) && is_string($severity)) {
            return ServiceCheck::new()->name($name)
                ->probe(resolve($probe))
                ->severity(Severity::from($severity));
        }

        $class = $entry['class'] ?? null;
        if (is_string($class) && is_a($class, Check::class, true)) {
            return resolve($class)->name($name);
        }

        throw InvalidHealthEndpointConfig::because("[extra_checks.{$name}]: set exactly one of [probe] or [class]");
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function extraChecks(): array {
        return array_filter(
            config()->array('health-endpoint.extra_checks', []),
            fn (mixed $entry, int|string $name): bool => is_string($name) && is_array($entry),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Tolerant on purpose: a bad value (for example HEALTH_EXPECTED_ENV=null) falls back to the default.
     */
    private function option(string $key, string $default): string {
        $value = config("health-endpoint.options.{$key}");

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function heartbeatStore(): ?string {
        $store = config('health-endpoint.options.heartbeat_cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function mailTransport(): mixed {
        $mailer = config('mail.default');

        return is_string($mailer) ? config("mail.mailers.{$mailer}.transport") : null;
    }

    /**
     * The driver of the default queue connection. queue.default is a connection name, which can differ from its driver.
     */
    private function queueDriver(): mixed {
        $connection = config('queue.default');

        return is_string($connection) ? config("queue.connections.{$connection}.driver") : null;
    }

    private function cacheDriver(): mixed {
        $store = config('cache.default');

        return is_string($store) ? config("cache.stores.{$store}.driver") : null;
    }

    /**
     * @throws InvalidHealthEndpointConfig
     */
    private function validate(): void {
        $checks = config('health-endpoint.checks', []);
        if (! is_array($checks)) {
            throw InvalidHealthEndpointConfig::because('[checks] must be an array');
        }

        foreach ($checks as $name => $value) {
            if (! array_key_exists($name, self::DEFAULTS)) {
                throw InvalidHealthEndpointConfig::because("[checks.{$name}] is not a shared check; add app checks to [extra_checks]");
            }

            if (! in_array($value, ['auto', true, false], true)) {
                throw InvalidHealthEndpointConfig::because("[checks.{$name}] must be 'auto', true or false");
            }
        }

        $this->validateExtraChecks();
        $this->validateMailProbes();
    }

    private function validateExtraChecks(): void {
        $extraChecks = config('health-endpoint.extra_checks', []);
        if (! is_array($extraChecks)) {
            throw InvalidHealthEndpointConfig::because('[extra_checks] must be an array');
        }

        foreach ($extraChecks as $name => $entry) {
            $key = "[extra_checks.{$name}]";

            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw InvalidHealthEndpointConfig::because("{$key}: the name must be snake_case");
            }

            if (array_key_exists($name, self::DEFAULTS)) {
                throw InvalidHealthEndpointConfig::because("{$key}: the name is a shared check");
            }

            if (! is_array($entry) || isset($entry['probe']) === isset($entry['class'])) {
                throw InvalidHealthEndpointConfig::because("{$key}: set exactly one of [probe] or [class]");
            }

            isset($entry['probe']) ? $this->validateProbeEntry($key, $entry) : $this->validateClassEntry($key, $entry);
        }
    }

    /**
     * @param  array<mixed>  $entry
     */
    private function validateProbeEntry(string $key, array $entry): void {
        if (! is_string($entry['probe']) || ! is_a($entry['probe'], Probe::class, true)) {
            throw InvalidHealthEndpointConfig::because("{$key}: [probe] must implement " . Probe::class);
        }

        if (! is_string($entry['severity'] ?? null) || Severity::tryFrom($entry['severity']) === null) {
            throw InvalidHealthEndpointConfig::because("{$key}: [severity] must be 'failed' or 'warning'");
        }

        if (isset($entry['meta'])) {
            throw InvalidHealthEndpointConfig::because("{$key}: a probe has no [meta]");
        }
    }

    /**
     * @param  array<mixed>  $entry
     */
    private function validateClassEntry(string $key, array $entry): void {
        if (! is_string($entry['class']) || ! is_a($entry['class'], Check::class, true)) {
            throw InvalidHealthEndpointConfig::because("{$key}: [class] must extend " . Check::class);
        }

        $meta = $entry['meta'] ?? [];
        if (! is_array($meta) || ! array_is_list($meta) || array_filter($meta, is_string(...)) !== $meta) {
            throw InvalidHealthEndpointConfig::because("{$key}: [meta] must be a list of strings");
        }
    }

    private function validateMailProbes(): void {
        $probes = config('health-endpoint.options.mail.probes', []);
        if (! is_array($probes)) {
            throw InvalidHealthEndpointConfig::because('[options.mail.probes] must be an array');
        }

        foreach ($probes as $transport => $class) {
            if (! is_string($class) || ! is_a($class, MailProbe::class, true)) {
                throw InvalidHealthEndpointConfig::because("[options.mail.probes.{$transport}] must implement " . MailProbe::class);
            }
        }
    }
}
