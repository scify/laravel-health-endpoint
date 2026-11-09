<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the check list is built from an invalid config/health-endpoint.php. Never at boot.
 */
final class InvalidHealthEndpointConfig extends InvalidArgumentException {
    public static function because(string $reason): self {
        return new self("Invalid health-endpoint config: {$reason}.");
    }
}
