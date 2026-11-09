<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Tests\Fixtures;

use SciFY\LaravelHealthEndpoint\Probes\Probe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;

/**
 * A probe whose answer each test sets.
 */
final class ScriptedProbe implements Probe {
    public static ?ProbeResult $answer = null;

    public function run(): ProbeResult {
        return self::$answer ?? ProbeResult::ok();
    }
}
