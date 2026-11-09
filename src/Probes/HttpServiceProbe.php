<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Base class for probes that make one authenticated HTTP request. A subclass declares the request; this class owns
 * the timeout, connection errors and the default status mapping.
 */
abstract class HttpServiceProbe {
    /**
     * Build and send the request, for example `$http->withToken($key)->get($url)`.
     */
    abstract protected function request(PendingRequest $http): Response;

    protected function timeoutSeconds(): int {
        return 5;
    }

    protected function send(): ProbeResult {
        try {
            $response = $this->request(Http::timeout($this->timeoutSeconds()));
        } catch (ConnectionException) {
            return ProbeResult::unreachable();
        }

        return $this->classify($response);
    }

    /**
     * 2xx is ok, 401 and 403 are rejected credentials, everything else is unreachable. Override for other services.
     */
    protected function classify(Response $response): ProbeResult {
        return match (true) {
            $response->successful() => ProbeResult::ok(),
            in_array($response->status(), [401, 403], true) => ProbeResult::rejected($response->status()),
            default => ProbeResult::unreachable(),
        };
    }
}
