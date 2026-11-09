<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SciFY\LaravelHealthEndpoint\Checks\ServiceCheck;
use SciFY\LaravelHealthEndpoint\Checks\Severity;
use SciFY\LaravelHealthEndpoint\Probes\HttpServiceProbe;
use SciFY\LaravelHealthEndpoint\Probes\Probe;
use SciFY\LaravelHealthEndpoint\Probes\ProbeResult;
use SciFY\LaravelHealthEndpoint\Probes\ProbeStatus;
use Spatie\Health\Checks\Result;

/**
 * The smallest HttpServiceProbe: what a new service needs to declare.
 */
function exampleServiceProbe(): Probe {
    return new class extends HttpServiceProbe implements Probe {
        public function run(): ProbeResult {
            return $this->send();
        }

        protected function request(PendingRequest $http): Response {
            return $http->withToken('secret')->get('https://api.example.org/v1/account');
        }
    };
}

describe('HttpServiceProbe', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('maps the response status', function (Closure $response, ProbeStatus $status, string $summary): void {
        // Arrange
        Http::fake(['https://api.example.org/v1/account' => $response()]);

        // Act
        $result = exampleServiceProbe()->run();

        // Assert
        expect($result->status)->toBe($status)
            ->and($result->summary)->toBe($summary);
    })->with([
        '200' => [fn (): Closure => fn () => Http::response('{}', 200), ProbeStatus::Ok, 'Connected'],
        '204' => [fn (): Closure => fn () => Http::response('', 204), ProbeStatus::Ok, 'Connected'],
        '401' => [fn (): Closure => fn () => Http::response('', 401), ProbeStatus::Problem, 'Rejected (401): invalid credentials'],
        '403' => [fn (): Closure => fn () => Http::response('', 403), ProbeStatus::Problem, 'Rejected (403): invalid credentials'],
        '404' => [fn (): Closure => fn () => Http::response('', 404), ProbeStatus::Problem, 'Unreachable'],
        '503' => [fn (): Closure => fn () => Http::response('', 503), ProbeStatus::Problem, 'Unreachable'],
        'connection error' => [fn (): Closure => fn () => Http::failedConnection(), ProbeStatus::Problem, 'Unreachable'],
    ]);

    it('sends the request that the subclass declares, once', function (): void {
        // Arrange
        Http::fake(['*' => Http::response('{}', 200)]);

        // Act
        exampleServiceProbe()->run();

        // Assert
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret'));
        Http::assertSentCount(1);
    });
});

describe('ServiceCheck', function (): void {
    it('maps a probe result to a check status by severity', function (ProbeResult $answer, Severity $severity, string $status): void {
        // Arrange
        $check = ServiceCheck::new()->name('example')->probe(new readonly class($answer) implements Probe {
            public function __construct(private ProbeResult $answer) {}

            public function run(): ProbeResult {
                return $this->answer;
            }
        })->severity($severity);

        // Act
        $result = $check->run();

        // Assert
        expect($result->status->value)->toBe($status)
            ->and($result->getShortSummary())->toBe($answer->summary);
    })->with([
        'ok' => [ProbeResult::ok(), Severity::Failed, 'ok'],
        'problem, failed severity' => [ProbeResult::unreachable(), Severity::Failed, 'failed'],
        'problem, warning severity' => [ProbeResult::unreachable(), Severity::Warning, 'warning'],
        'skipped' => [ProbeResult::skipped(), Severity::Failed, 'skipped'],
    ]);

    it('throws without a probe, so the runner reports it as crashed', function (): void {
        expect(fn (): Result => ServiceCheck::new()->name('example')->run())->toThrow(LogicException::class);
    });
});
