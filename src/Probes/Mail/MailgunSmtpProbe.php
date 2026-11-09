<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes\Mail;

use SciFY\LaravelHealthEndpoint\Probes\MailProbe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * For Mailgun domain sending keys, which cannot read the domains API: an SMTP handshake with separate credentials.
 */
final readonly class MailgunSmtpProbe implements MailProbe {
    public function __construct(private SmtpHandshake $handshake) {}

    public function run(string $mailer): ProbeResult {
        $username = config()->string('health-endpoint.options.mail.mailgun_smtp_username', '');
        $password = config()->string('health-endpoint.options.mail.mailgun_smtp_password', '');

        // Symfony skips AUTH when the username is empty, so the handshake alone would report a false ok.
        if ($username === '' || $password === '') {
            return ProbeResult::problem('SMTP credentials not configured');
        }

        $transport = new EsmtpTransport(
            config()->string('health-endpoint.options.mail.mailgun_smtp_host', 'smtp.eu.mailgun.org'),
            config()->integer('health-endpoint.options.mail.mailgun_smtp_port', 587),
        );
        $transport->setUsername($username);
        $transport->setPassword($password);

        return ($this->handshake)($transport);
    }
}
