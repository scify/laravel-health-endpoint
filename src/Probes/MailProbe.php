<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes;

/**
 * Proves that the provider behind one mail transport is reachable and accepts the credentials. Never sends a message.
 */
interface MailProbe {
    /**
     * @param  string  $mailer  The name of the mailer whose transport is probed (mail.default).
     */
    public function run(string $mailer): ProbeResult;
}
