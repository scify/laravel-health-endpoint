<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes\Mail;

use Illuminate\Support\Str;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Connect, EHLO, STARTTLS, AUTH, QUIT. No message is sent.
 */
final class SmtpHandshake {
    public function __invoke(EsmtpTransport $transport): ProbeResult {
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            // Applies to each socket operation, not to the whole handshake.
            $stream->setTimeout(config()->integer('health-endpoint.options.mail.timeout_seconds', 5));
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $transportException) {
            return Str::contains($transportException->getMessage(), 'authenticate', ignoreCase: true)
                ? ProbeResult::problem('Authentication failed')
                : ProbeResult::unreachable();
        }

        return ProbeResult::ok();
    }
}
