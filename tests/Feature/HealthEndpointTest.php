<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use SciFY\LaravelHealthEndpoint\Tests\Fixtures\ScriptedCheck;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;

/**
 * Registers a scripted app check under the given name.
 *
 * @param  list<string>  $meta
 */
function scriptedCheck(string $name, Closure $run, array $meta = []): void {
    config()->set("health-endpoint.extra_checks.{$name}", ['class' => ScriptedCheck::class, 'meta' => $meta]);
    ScriptedCheck::script($name, $run);
}

describe('GET /health', function (): void {
    beforeEach(function (): void {
        $this->token = str_repeat('a1', 32);
        config()->set('health-endpoint.token', $this->token);
        config()->set('health-endpoint.rate_limits.per_ip', 100);
        config()->set('health-endpoint.rate_limits.global', 100);
        withoutSharedChecks();

        scriptedCheck('primary_store', fn (): Result => Result::make()
            ->meta(['connection_name' => 'sqlite', 'host' => 'db.internal'])
            ->shortSummary('Connected')
            ->ok(), meta: ['connection_name']);
        scriptedCheck('secondary_store', fn (): Result => Result::make()->shortSummary('Working')->ok());
    });

    describe('token', function (): void {
        it('returns 404 with an empty body without the token header', function (): void {
            // Act
            $response = $this->get('/health');

            // Assert
            $response->assertNotFound();

            expect($response->getContent())->toBe('');
        });

        it('sends the 404 as plain text, so no HTML is injected into it', function (): void {
            // Act
            $response = $this->get('/health');

            // Assert
            expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
        });

        it('returns 404 for a wrong token', function (): void {
            $this->get('/health', ['X-Health-Token' => 'wrong'])->assertNotFound();
        });

        it('returns 404 for the token with surrounding whitespace', function (): void {
            $this->get('/health', ['X-Health-Token' => " {$this->token} "])->assertNotFound();
        });

        it('returns 404 for the token in the query string', function (): void {
            $this->get('/health?token=' . $this->token)->assertNotFound();
        });

        it('returns 404 for every request when no token is configured', function (): void {
            // Arrange
            config()->set('health-endpoint.token', '');

            // Act + Assert
            $this->get('/health', ['X-Health-Token' => ''])->assertNotFound();
            $this->get('/health', ['X-Health-Token' => $this->token])->assertNotFound();
        });
    });

    describe('response', function (): void {
        it('returns the envelope with HTTP 200 when every check passes', function (): void {
            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            $response->assertOk()
                ->assertJsonPath('schema_version', '1.0')
                ->assertJsonPath('status', 'ok')
                ->assertJsonPath('app.name', config('app.name'))
                ->assertJsonPath('app.environment', config('app.env'))
                ->assertJsonPath('checks.0.name', 'primary_store')
                ->assertJsonPath('checks.0.status', 'ok')
                ->assertJsonPath('checks.0.summary', 'Connected')
                ->assertJsonPath('checks.0.meta', ['connection_name' => 'sqlite'])
                ->assertJsonPath('checks.1.name', 'secondary_store')
                ->assertJsonStructure(['checked_at', 'duration_ms', 'checks' => [['duration_ms']]]);
            expect($response->json('checked_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
            $this->assertMatchesHealthSchema((string) $response->getContent());
        });

        it('encodes an empty meta as a JSON object', function (): void {
            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            expect($response->getContent())->toContain('"name":"secondary_store","status":"ok","summary":"Working","duration_ms":')
                ->toContain('"meta":{}');
        });

        it('returns HTTP 200 with status warning when a check warns', function (): void {
            // Arrange
            scriptedCheck('backup_age', fn (): Result => Result::make()->shortSummary('2 days')->warning());

            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            $response->assertOk()->assertJsonPath('status', 'warning');
            $this->assertMatchesHealthSchema((string) $response->getContent());
        });

        it('returns HTTP 503 with status failed when a check fails', function (): void {
            // Arrange
            scriptedCheck('payment_gateway', fn (): Result => Result::make()->shortSummary('Unreachable')->failed());

            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            $response->assertStatus(503)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('checks.2.status', 'failed');
            $this->assertMatchesHealthSchema((string) $response->getContent());
        });

        it('reports a throwing check as crashed without leaking the exception message', function (): void {
            // Arrange
            Exceptions::fake();
            scriptedCheck('payment_gateway', fn (): Result => throw new RuntimeException('secret db.internal:3306'));

            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            $response->assertStatus(503)
                ->assertJsonPath('checks.2.status', 'crashed')
                ->assertJsonPath('checks.2.summary', 'Check crashed');
            expect($response->getContent())->not->toContain('db.internal');
            Exceptions::assertReported(RuntimeException::class);
            $this->assertMatchesHealthSchema((string) $response->getContent());
        });

        it('returns HTTP 500 for an invalid config, which the monitor treats as down', function (): void {
            // Arrange
            config()->set('health-endpoint.checks.mail', 'yes');

            // Act + Assert
            $this->withoutExceptionHandling();
            expect(fn () => $this->get('/health', ['X-Health-Token' => $this->token]))
                ->toThrow(InvalidArgumentException::class, '[checks.mail]');
        });

        it('sends no-store and noindex headers', function (): void {
            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            expect($response->headers->get('Cache-Control'))->toContain('no-store');
            $response->assertHeader('X-Robots-Tag', 'noindex');
        });

        it('sets no cookie', function (): void {
            // Act
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            expect($response->headers->getCookies())->toBe([]);
        });

        it('reports a check that is not due as skipped', function (): void {
            // Arrange: a scripted check that would fail, but whose condition is false.
            config()->set('health-endpoint.extra_checks.nightly_job', ['class' => SkippedScriptedCheck::class]);

            // Act + Assert
            $this->get('/health', ['X-Health-Token' => $this->token])
                ->assertOk()
                ->assertJsonPath('status', 'ok')
                ->assertJsonPath('checks.2.status', Status::skipped()->value);
        });
    });

    describe('rate limits', function (): void {
        it('limits requests per IP before the token check', function (): void {
            // Arrange
            config()->set('health-endpoint.rate_limits.per_ip', 2);

            // Act
            $this->get('/health', ['X-Health-Token' => 'wrong'])->assertNotFound();
            $this->get('/health', ['X-Health-Token' => 'wrong'])->assertNotFound();
            $response = $this->get('/health', ['X-Health-Token' => 'wrong']);

            // Assert
            $response->assertTooManyRequests();
            $response->assertHeader('Retry-After');
        });

        it('limits check runs globally after the token check', function (): void {
            // Arrange
            config()->set('health-endpoint.rate_limits.global', 2);

            // Act
            $this->get('/health', ['X-Health-Token' => $this->token])->assertOk();
            $this->get('/health', ['X-Health-Token' => $this->token])->assertOk();
            $response = $this->get('/health', ['X-Health-Token' => $this->token]);

            // Assert
            $response->assertTooManyRequests();
        });

        it('does not count requests with a wrong token against the run limit', function (): void {
            // Arrange
            config()->set('health-endpoint.rate_limits.global', 1);

            // Act
            $this->get('/health', ['X-Health-Token' => 'wrong'])->assertNotFound();
            $this->get('/health', ['X-Health-Token' => 'wrong'])->assertNotFound();
            $this->get('/health', ['X-Health-Token' => 'wrong'])->assertNotFound();

            // Assert
            $this->get('/health', ['X-Health-Token' => $this->token])->assertOk();
        });
    });
});

/**
 * A check that is never due, like a check with a frequency outside its slot.
 */
final class SkippedScriptedCheck extends Check {
    public function shouldRun(): bool {
        return false;
    }

    public function run(): Result {
        return Result::make()->failed();
    }
}
