<?php

declare(strict_types=1);

use SciFY\LaravelHealthEndpoint\CheckRegistry;
use SciFY\LaravelHealthEndpoint\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Turns every shared check off, so a test sees only the checks it adds.
 */
function withoutSharedChecks(): void {
    config()->set('health-endpoint.checks', array_fill_keys(array_keys(CheckRegistry::DEFAULTS), false));
}
