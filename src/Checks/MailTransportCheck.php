<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Checks;

use SciFY\LaravelHealthEndpoint\Probes\MailProbe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Proves that the mail provider is reachable and accepts the credentials, without sending a message.
 *
 * The probe comes from health-endpoint.options.mail.probes, keyed by the transport of mail.default.
 */
final class MailTransportCheck extends Check {
    public function run(): Result {
        $mailer = config()->string('mail.default');
        $transport = config()->string("mail.mailers.{$mailer}.transport");

        $probe = config()->array('health-endpoint.options.mail.probes', [])[$transport] ?? null;
        $probeResult = is_string($probe) && is_a($probe, MailProbe::class, true)
            ? resolve($probe)->run($mailer)
            : ProbeResult::skipped();

        return ServiceCheck::toResult($probeResult, Severity::Failed)->meta(['driver' => $transport]);
    }
}
