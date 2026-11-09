<?php

declare(strict_types=1);

use SciFY\LaravelHealthEndpoint\Probes\Mail\MailgunApiProbe;
use SciFY\LaravelHealthEndpoint\Probes\Mail\MailgunSmtpProbe;
use SciFY\LaravelHealthEndpoint\Probes\Mail\SmtpProbe;

return [
    /*
     * false removes the route and the heartbeat schedules. The commands stay available.
     */
    'enabled' => env('HEALTH_ENDPOINT_ENABLED', true),

    'path' => 'health',

    /*
     * The monitor sends this in the X-Health-Token header. Empty disables the endpoint: every request gets 404.
     * Generate one per app and environment: openssl rand -hex 32
     */
    'token' => env('HEALTH_TOKEN', ''),

    'rate_limits' => [
        // Requests per minute per client IP, counted before the token check.
        'per_ip' => 10,
        // Check runs per minute in total, counted after the token check.
        'global' => 30,
    ],

    /*
     * 'auto': register the check when the app uses the dependency (see the README).
     * true: always register. false: never register.
     * Set 'schedule' to false when production has no cron for schedule:run; 'queue' then turns off too.
     */
    'checks' => [
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
    ],

    'options' => [
        'expected_environment' => env('HEALTH_EXPECTED_ENV', 'production'),

        // Spatie's default of 1 minute flaps when schedule:run starts a few seconds late.
        'schedule_max_age_minutes' => 2,

        'disk' => [
            // null: storage_path().
            'path' => null,
            'warn_above' => 70,
            'fail_above' => 90,
        ],

        'mail' => [
            'timeout_seconds' => 5,

            /*
             * Transport of mail.default => probe class. A transport without a probe reports "skipped".
             * HEALTH_MAILGUN_PROBE=smtp is for Mailgun domain sending keys, which cannot read the domains API.
             */
            'probes' => [
                'smtp' => SmtpProbe::class,
                'mailgun' => env('HEALTH_MAILGUN_PROBE', 'api') === 'smtp'
                    ? MailgunSmtpProbe::class
                    : MailgunApiProbe::class,
            ],

            // Only for HEALTH_MAILGUN_PROBE=smtp. Match the region of services.mailgun.endpoint.
            'mailgun_smtp_host' => env('HEALTH_MAILGUN_SMTP_HOST', 'smtp.eu.mailgun.org'),
            'mailgun_smtp_port' => 587,
            'mailgun_smtp_username' => env('HEALTH_MAILGUN_SMTP_USERNAME', ''),
            'mailgun_smtp_password' => env('HEALTH_MAILGUN_SMTP_PASSWORD', ''),
        ],

        'error_tracker' => [
            'timeout_seconds' => 3,
        ],

        // Cache store for the schedule and queue heartbeats. null: the default store.
        'heartbeat_cache_store' => null,
    ],

    /*
     * App-specific checks. The key is the contract name (snake_case). Either a probe with a severity:
     *     'payment_gateway' => ['probe' => App\Health\PaymentGatewayProbe::class, 'severity' => 'failed'],
     * or a full Spatie check, with the meta keys that may reach the response:
     *     'search_index' => ['class' => App\Health\SearchIndexCheck::class, 'meta' => ['documents']],
     * Severity 'failed' only when users are affected; otherwise 'warning'.
     */
    'extra_checks' => [],
];
