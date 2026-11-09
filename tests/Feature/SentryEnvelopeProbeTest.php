<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SciFY\LaravelHealthEndpoint\Probes\ProbeStatus;
use SciFY\LaravelHealthEndpoint\Probes\SentryEnvelopeProbe;

describe('SentryEnvelopeProbe', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        config()->set('sentry.dsn', 'https://publickey@glitchtip.example.org/3');
    });

    it('posts an empty envelope with the key in the header', function (): void {
        // Arrange
        Http::fake(['https://glitchtip.example.org/api/3/envelope/' => Http::response('{}', 200)]);

        // Act
        $result = (new SentryEnvelopeProbe)->run();

        // Assert
        expect($result->status)->toBe(ProbeStatus::Ok)
            ->and($result->summary)->toBe('Accepted');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://glitchtip.example.org/api/3/envelope/'
            && str_contains($request->header('X-Sentry-Auth')[0] ?? '', 'sentry_key=publickey,')
            && $request->header('Content-Type') === ['application/x-sentry-envelope']
            // An envelope header without items, and no "dsn" field: no event, and the key is checked first.
            && $request->body() === "{}\n");
    });

    it('keeps the port and the path prefix of the DSN', function (): void {
        // Arrange
        config()->set('sentry.dsn', 'https://publickey@sentry.example.org:8443/tracker/12');
        Http::fake(['https://sentry.example.org:8443/tracker/api/12/envelope/' => Http::response('{}', 200)]);

        // Act + Assert
        expect((new SentryEnvelopeProbe)->run()->status)->toBe(ProbeStatus::Ok);
    });

    it('accepts a throttled key, because a throttled key is a valid key', function (): void {
        // Arrange
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '60'])]);

        // Act
        $result = (new SentryEnvelopeProbe)->run();

        // Assert
        expect($result->status)->toBe(ProbeStatus::Ok)
            ->and($result->summary)->toBe('Accepted (throttled)');
    });

    it('reports a rejected key', function (int $status): void {
        // Arrange
        Http::fake(['*' => Http::response(['detail' => 'Denied'], $status)]);

        // Act
        $result = (new SentryEnvelopeProbe)->run();

        // Assert
        expect($result->status)->toBe(ProbeStatus::Problem)
            ->and($result->summary)->toBe('Key rejected');
    })->with([401, 403]);

    it('reports other statuses and connection errors as unreachable', function (Closure $response): void {
        // Arrange
        Http::fake(['*' => $response()]);

        // Act
        $result = (new SentryEnvelopeProbe)->run();

        // Assert
        expect($result->status)->toBe(ProbeStatus::Problem)
            ->and($result->summary)->toBe('Unreachable');
    })->with([
        '500' => fn (): Closure => fn () => Http::response('', 500),
        '400' => fn (): Closure => fn () => Http::response('Invalid envelope header', 400),
        'connection error' => fn (): Closure => fn () => Http::failedConnection(),
    ]);

    it('reports an invalid DSN without a request', function (?string $dsn): void {
        // Arrange
        config()->set('sentry.dsn', $dsn);

        // Act
        $result = (new SentryEnvelopeProbe)->run();

        // Assert
        expect($result->status)->toBe(ProbeStatus::Problem)
            ->and($result->summary)->toBe('Invalid DSN');
        Http::assertNothingSent();
    })->with([
        'not a URL' => 'not a dsn',
        'no key' => 'https://glitchtip.example.org/3',
        'no project' => 'https://publickey@glitchtip.example.org/',
        'project not numeric' => 'https://publickey@glitchtip.example.org/abc',
        'null' => null,
    ]);
});
