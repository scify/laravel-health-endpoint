<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SciFY\LaravelHealthEndpoint\Http\Controllers\HealthController;
use SciFY\LaravelHealthEndpoint\Http\Middleware\VerifyHealthToken;

// Outside the web and api groups: no session, cookies, CSRF, or Inertia.
// The order is part of the design: per-ip runs before the token check and global after it,
// so requests without a valid token can never use up the global run limit and lock out the monitor.
if (config()->boolean('health-endpoint.enabled', true)) {
    Route::get(config()->string('health-endpoint.path', 'health'), HealthController::class)
        ->middleware(['throttle:health-endpoint.per-ip', VerifyHealthToken::class, 'throttle:health-endpoint.global'])
        ->name('health-endpoint');
}
