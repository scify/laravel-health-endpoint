<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Checks;

use LogicException;
use SciFY\LaravelHealthEndpoint\Probes\Probe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;
use SciFY\LaravelHealthEndpoint\Probes\ProbeStatus;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;

/**
 * Runs one probe and turns a problem into "failed" or "warning", by the severity.
 */
final class ServiceCheck extends Check {
    private ?Probe $probe = null;

    private Severity $severity = Severity::Failed;

    public function probe(Probe $probe): self {
        $this->probe = $probe;

        return $this;
    }

    public function severity(Severity $severity): self {
        $this->severity = $severity;

        return $this;
    }

    public function run(): Result {
        if (! $this->probe instanceof Probe) {
            throw new LogicException("The service check [{$this->getName()}] has no probe.");
        }

        return self::toResult($this->probe->run(), $this->severity);
    }

    /**
     * Shared with MailTransportCheck, so every probe result maps the same way.
     */
    public static function toResult(ProbeResult $probeResult, Severity $severity): Result {
        $result = Result::make()->shortSummary($probeResult->summary);

        if ($probeResult->status === ProbeStatus::Skipped) {
            // Result has no skipped() helper.
            $result->status = Status::skipped();

            return $result;
        }

        if ($probeResult->status === ProbeStatus::Ok) {
            return $result->ok();
        }

        return $severity === Severity::Failed ? $result->failed() : $result->warning();
    }
}
