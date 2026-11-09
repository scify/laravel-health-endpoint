<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Probes;

use Composer\InstalledVersions;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Proves that the Sentry DSN (GlitchTip or self-hosted Sentry) is accepted, without creating an event.
 *
 * The ingest service checks the key in X-Sentry-Auth before it reads the body, and an envelope header without items
 * creates no event, no issue and uses no quota. Limit: GlitchTip accepts a disabled key.
 */
final class SentryEnvelopeProbe extends HttpServiceProbe implements Probe {
    public function run(): ProbeResult {
        return $this->target() === null ? ProbeResult::problem('Invalid DSN') : $this->send();
    }

    protected function request(PendingRequest $http): Response {
        // run() only calls send() for a DSN that parses.
        $target = $this->target() ?? ['url' => '', 'key' => ''];

        return $http
            ->withHeaders(['X-Sentry-Auth' => sprintf(
                'Sentry sentry_version=7, sentry_key=%s, sentry_client=scify-laravel-health-endpoint/%s',
                $target['key'],
                InstalledVersions::getPrettyVersion('scify/laravel-health-endpoint') ?? 'dev',
            )])
            // The key goes in the header, never in a "dsn" field of the body, so it is checked before the body is read.
            ->withBody("{}\n", 'application/x-sentry-envelope')
            ->post($target['url']);
    }

    protected function timeoutSeconds(): int {
        return config()->integer('health-endpoint.options.error_tracker.timeout_seconds', 3);
    }

    protected function classify(Response $response): ProbeResult {
        return match (true) {
            $response->successful() => ProbeResult::ok('Accepted'),
            $response->status() === 429 => ProbeResult::ok('Accepted (throttled)'),
            in_array($response->status(), [401, 403], true) => ProbeResult::problem('Key rejected'),
            default => ProbeResult::unreachable(),
        };
    }

    /**
     * {scheme}://{key}@{host}{:port}{/prefix}/{project_id} → {scheme}://{host}{:port}{/prefix}/api/{project_id}/envelope/
     *
     * @return array{url: string, key: string}|null
     */
    private function target(): ?array {
        $dsn = config('sentry.dsn');
        $parts = is_string($dsn) ? parse_url($dsn) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            return null;
        }

        $path = trim($parts['path'], '/');
        $projectId = Str::afterLast($path, '/');
        $prefix = str_contains($path, '/') ? '/' . Str::beforeLast($path, '/') : '';

        if ($parts['user'] === '' || ! ctype_digit($projectId)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return [
            'url' => "{$parts['scheme']}://{$parts['host']}{$port}{$prefix}/api/{$projectId}/envelope/",
            'key' => $parts['user'],
        ];
    }
}
