<?php

declare(strict_types=1);

use SciFY\LaravelHealthEndpoint\CheckOutcome;
use SciFY\LaravelHealthEndpoint\ResultMapper;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;

function healthOutcome(string $name, Status $status, string $summary = '', array $meta = [], int $durationMs = 1): CheckOutcome {
    return new CheckOutcome($name, (new Result($status, '', $summary))->meta($meta), $durationMs);
}

function healthEnvelope(array $outcomes): array {
    return (new ResultMapper)->toEnvelope($outcomes, [
        'database' => ['connection_name'],
        'cache' => ['driver'],
        'mail' => ['driver'],
        'disk' => ['disk_space_used_percentage'],
    ], 42, '2026-10-07T10:00:00Z', 'Example App', 'production');
}

describe('ResultMapper', function (): void {
    it('builds the envelope fields', function (): void {
        // Act
        $envelope = healthEnvelope([healthOutcome('database', Status::ok(), 'Connected', [], 8)]);

        // Assert
        expect($envelope['schema_version'])->toBe(ResultMapper::SCHEMA_VERSION)
            ->and($envelope['checked_at'])->toBe('2026-10-07T10:00:00Z')
            ->and($envelope['duration_ms'])->toBe(42)
            ->and($envelope['app'])->toBe(['name' => 'Example App', 'environment' => 'production'])
            ->and($envelope['checks'][0]['name'])->toBe('database')
            ->and($envelope['checks'][0]['status'])->toBe('ok')
            ->and($envelope['checks'][0]['summary'])->toBe('Connected')
            ->and($envelope['checks'][0]['duration_ms'])->toBe(8);
    });

    it('reports ok when every check is ok', function (): void {
        expect(healthEnvelope([
            healthOutcome('database', Status::ok()),
            healthOutcome('cache', Status::ok()),
        ])['status'])->toBe('ok');
    });

    it('reports warning when a check warns and none fails', function (): void {
        expect(healthEnvelope([
            healthOutcome('database', Status::ok()),
            healthOutcome('disk', Status::warning()),
        ])['status'])->toBe('warning');
    });

    it('reports failed when a check fails', function (): void {
        expect(healthEnvelope([
            healthOutcome('disk', Status::warning()),
            healthOutcome('mail', Status::failed()),
        ])['status'])->toBe('failed');
    });

    it('reports failed when a check crashes', function (): void {
        expect(healthEnvelope([healthOutcome('database', Status::crashed())])['status'])->toBe('failed');
    });

    it('ignores skipped checks for the overall status', function (): void {
        expect(healthEnvelope([
            healthOutcome('database', Status::ok()),
            healthOutcome('mail', Status::skipped()),
        ])['status'])->toBe('ok');
    });

    it('reports ok with an empty list when no check is registered', function (): void {
        // Act
        $envelope = healthEnvelope([]);

        // Assert
        expect($envelope['status'])->toBe('ok')
            ->and($envelope['checks'])->toBe([]);
    });

    it('replaces the summary of a crashed check with a generic one', function (): void {
        // Act
        $check = healthEnvelope([healthOutcome('database', Status::crashed(), 'SQLSTATE host db.internal')])['checks'][0];

        // Assert
        expect($check['summary'])->toBe('Check crashed');
    });

    it('keeps only allow-listed meta keys', function (): void {
        // Act
        $check = healthEnvelope([healthOutcome('database', Status::ok(), '', [
            'connection_name' => 'mysql',
            'host' => 'db.internal',
        ])])['checks'][0];

        // Assert
        expect($check['meta'])->toEqual((object) ['connection_name' => 'mysql']);
    });

    it('drops all meta of a check that has no allow-list', function (): void {
        // Act
        $check = healthEnvelope([healthOutcome('queue', Status::failed(), '', ['The `default` queue did not run yet.'])])['checks'][0];

        // Assert
        expect($check['meta'])->toEqual(new stdClass);
    });

    it('encodes empty meta as a JSON object', function (): void {
        // Act
        $json = json_encode(healthEnvelope([healthOutcome('cache', Status::ok())]), JSON_THROW_ON_ERROR);

        // Assert
        expect($json)->toContain('"meta":{}');
    });

    describe('heartbeat summaries', function (): void {
        /**
         * @param  array<int|string, mixed>  $meta
         */
        function heartbeatSummary(string $name, string $message, array $meta = []): string {
            $outcome = new CheckOutcome($name, (new Result(Status::failed(), $message))->meta($meta), 1);

            return healthEnvelope([$outcome])['checks'][0]['summary'];
        }

        it('says no heartbeat yet when the schedule never ran', function (): void {
            expect(heartbeatSummary('schedule', 'The schedule did not run yet.'))->toBe('No heartbeat yet');
        });

        it('says the heartbeat is too old when the schedule stopped', function (): void {
            expect(heartbeatSummary('schedule', 'The last run of the schedule was more than 2 minutes ago.'))
                ->toBe('Heartbeat too old');
        });

        it('reads the queue reason from the meta list', function (): void {
            expect(heartbeatSummary('queue', 'Queue jobs running failed. Check meta for more information.', [
                'The `default` queue did not run yet.',
            ]))->toBe('No heartbeat yet');
        });

        it('says the queue heartbeat is too old when the worker stopped', function (): void {
            expect(heartbeatSummary('queue', 'Queue jobs running failed. Check meta for more information.', [
                'The last run of the `default` queue was more than 5 minutes ago.',
            ]))->toBe('Heartbeat too old');
        });

        it('keeps the generic summary for an unknown heartbeat message', function (): void {
            expect(heartbeatSummary('schedule', 'Something else happened.'))->toBe('Failed');
        });

        it('never derives a summary from the message of other checks', function (): void {
            expect(heartbeatSummary('database', 'Could not connect: host db.internal did not run yet'))->toBe('Failed');
        });
    });
});
