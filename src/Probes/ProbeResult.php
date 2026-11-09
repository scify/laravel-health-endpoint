<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes;

/**
 * The answer of one probe. The summary is a fixed text: never exception text, hostnames or credentials.
 */
final readonly class ProbeResult {
    private function __construct(
        public ProbeStatus $status,
        public string $summary,
    ) {}

    public static function ok(string $summary = 'Connected'): self {
        return new self(ProbeStatus::Ok, $summary);
    }

    public static function problem(string $summary): self {
        return new self(ProbeStatus::Problem, $summary);
    }

    public static function rejected(int $httpStatus, string $reason = 'invalid credentials'): self {
        return self::problem(sprintf('Rejected (%d): %s', $httpStatus, $reason));
    }

    public static function unreachable(): self {
        return self::problem('Unreachable');
    }

    /**
     * The probe cannot check this setup (for example an unsupported transport). Never reported as ok.
     */
    public static function skipped(string $summary = 'Driver not supported'): self {
        return new self(ProbeStatus::Skipped, $summary);
    }
}
