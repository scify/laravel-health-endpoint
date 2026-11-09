<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes;

/**
 * Asks one external service whether it answers and accepts the app's credentials, without side effects.
 */
interface Probe {
    public function run(): ProbeResult;
}
