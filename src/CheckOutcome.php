<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint;

use Spatie\Health\Checks\Result;

/**
 * The result of one health check run, with the contract name and the time it took.
 */
final readonly class CheckOutcome {
    public function __construct(
        public string $name,
        public Result $result,
        public int $durationMs,
    ) {}
}
