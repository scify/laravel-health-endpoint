<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes\Mail;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use SciFY\LaravelHealthEndpoint\Probes\HttpServiceProbe;
use SciFY\LaravelHealthEndpoint\Probes\MailProbe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;

/**
 * Reads the Mailgun domain resource with the app's key. Needs an account key: a domain sending key gets 401.
 */
final class MailgunApiProbe extends HttpServiceProbe implements MailProbe {
    public function run(string $mailer): ProbeResult {
        return $this->send();
    }

    protected function request(PendingRequest $http): Response {
        $url = sprintf(
            'https://%s/v3/domains/%s',
            config()->string('services.mailgun.endpoint'),
            config()->string('services.mailgun.domain'),
        );

        return $http->withBasicAuth('api', config()->string('services.mailgun.secret'))->get($url);
    }

    protected function timeoutSeconds(): int {
        return config()->integer('health-endpoint.options.mail.timeout_seconds', 5);
    }

    protected function classify(Response $response): ProbeResult {
        // Mailgun answers 401 both for a bad key and for an IP outside its allowlist.
        return in_array($response->status(), [401, 403], true)
            ? ProbeResult::rejected($response->status(), 'invalid key or IP not allowlisted')
            : parent::classify($response);
    }
}
