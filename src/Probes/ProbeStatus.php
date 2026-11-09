<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes;

enum ProbeStatus {
    case Ok;
    case Problem;
    case Skipped;
}
