<?php

/*
 * Prints what the laravel-health-endpoint skill needs to know about a Laravel app, as JSON.
 * Run it from the app root: php < detect.php (it reads vendor/ and bootstrap/ from the working directory).
 * It boots the app with the local .env, so the drivers are LOCAL values. It prints no secrets: only whether a DSN
 * or key is set. Runs on any PHP 8 and Laravel 8+, so it can also report an app that is too old for the package.
 */

require getcwd() . '/vendor/autoload.php';

$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$driverOf = static function (string $group, ?string $name, string $key = 'driver') {
    return is_string($name) ? config("{$group}.{$name}.{$key}") : null;
};

$mailer = config('mail.default');
$queue = config('queue.default');
$cache = config('cache.default');

$healthRoutes = [];
foreach (app('router')->getRoutes() as $route) {
    if (str_contains($route->uri(), 'health') || $route->uri() === 'up') {
        $healthRoutes[] = implode('|', $route->methods()) . ' /' . ltrim($route->uri(), '/');
    }
}

echo json_encode([
    'php' => PHP_VERSION,
    'laravel' => $app->version(),
    'health_endpoint_package_installed' => class_exists('SciFY\\LaravelHealthEndpoint\\HealthEndpointServiceProvider'),
    'spatie_laravel_health_installed' => class_exists(Spatie\Health\Health::class),
    'local' => [
        'app_env' => config('app.env'),
        'mail_default' => $mailer,
        'mail_transport' => $driverOf('mail.mailers', is_string($mailer) ? $mailer : null, 'transport'),
        'queue_default' => $queue,
        'queue_driver' => $driverOf('queue.connections', is_string($queue) ? $queue : null),
        'cache_default' => $cache,
        'cache_driver' => $driverOf('cache.stores', is_string($cache) ? $cache : null),
        'session_driver' => config('session.driver'),
        'sentry_dsn_set' => filled(config('sentry.dsn')),
        'mailgun_domain_set' => filled(config('services.mailgun.domain')),
        'mailgun_endpoint' => config('services.mailgun.endpoint'),
    ],
    'proc_open_available' => function_exists('proc_open'),
    'expose_php' => ini_get('expose_php'),
    'health_like_routes' => $healthRoutes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
