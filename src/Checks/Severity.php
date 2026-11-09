<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Checks;

/**
 * What a probe problem means for the app: "failed" when users are affected, "warning" when they are not.
 */
enum Severity: string {
    case Failed = 'failed';
    case Warning = 'warning';
}
