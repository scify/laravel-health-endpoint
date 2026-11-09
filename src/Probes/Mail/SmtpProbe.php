<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes\Mail;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use SciFY\LaravelHealthEndpoint\Probes\MailProbe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Handshakes with the app's own configured SMTP transport.
 */
final readonly class SmtpProbe implements MailProbe {
    public function __construct(private SmtpHandshake $handshake) {}

    public function run(string $mailer): ProbeResult {
        $instance = Mail::mailer($mailer);
        $transport = $instance instanceof Mailer ? $instance->getSymfonyTransport() : null;

        return $transport instanceof EsmtpTransport ? ($this->handshake)($transport) : ProbeResult::skipped();
    }
}
