<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint;

use Illuminate\Support\Arr;
use Spatie\Health\Checks\Result;

/**
 * Maps Spatie health check results to the shared /health JSON contract (resources/schema/health-response.schema.json).
 *
 * The only class that knows the Spatie Result API, so a package update touches one place.
 */
final class ResultMapper {
    public const string SCHEMA_VERSION = '1.0';

    /**
     * Checks whose failure reason maps to a fixed summary (see heartbeatSummary()).
     */
    private const array HEARTBEAT_CHECKS = ['schedule', 'queue'];

    /**
     * @param  list<CheckOutcome>  $outcomes
     * @param  array<string, list<string>>  $metaAllowList  Meta keys each check may expose. Every other key is dropped.
     *
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     checked_at: string,
     *     duration_ms: int,
     *     app: array{name: string, environment: string},
     *     checks: list<array{name: string, status: string, summary: string, duration_ms: int, meta: object}>
     * }
     */
    public function toEnvelope(
        array $outcomes,
        array $metaAllowList,
        int $durationMs,
        string $checkedAt,
        string $appName,
        string $environment,
    ): array {
        $checks = array_map(fn (CheckOutcome $outcome): array => $this->mapCheck($outcome, $metaAllowList), $outcomes);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->overallStatus($checks),
            'checked_at' => $checkedAt,
            'duration_ms' => $durationMs,
            'app' => ['name' => $appName, 'environment' => $environment],
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<string, list<string>>  $metaAllowList
     *
     * @return array{name: string, status: string, summary: string, duration_ms: int, meta: object}
     */
    private function mapCheck(CheckOutcome $outcome, array $metaAllowList): array {
        $status = (string) $outcome->result->status->value;

        return [
            'name' => $outcome->name,
            'status' => $status,
            'summary' => $this->summary($outcome, $status),
            'duration_ms' => $outcome->durationMs,
            // Cast to object: an empty PHP array would encode as a JSON array instead of {}.
            'meta' => (object) Arr::only($outcome->result->meta, $metaAllowList[$outcome->name] ?? []),
        ];
    }

    /**
     * Messages can carry exception text, so they are never passed through. The heartbeat checks get a fixed text
     * chosen from their message instead, because their generic "Failed" hides the cause.
     */
    private function summary(CheckOutcome $outcome, string $status): string {
        if ($status === 'crashed') {
            return 'Check crashed';
        }

        if ($status === 'failed' && in_array($outcome->name, self::HEARTBEAT_CHECKS, true)) {
            return $this->heartbeatSummary($outcome->result);
        }

        return $outcome->result->getShortSummary();
    }

    /**
     * ScheduleCheck puts its reason in the message; QueueCheck puts one reason per queue in meta.
     */
    private function heartbeatSummary(Result $result): string {
        $texts = implode(' ', array_filter([$result->notificationMessage, ...array_values($result->meta)], is_string(...)));

        return match (true) {
            str_contains($texts, 'did not run yet') => 'No heartbeat yet',
            str_contains($texts, 'was more than') => 'Heartbeat too old',
            default => $result->getShortSummary(),
        };
    }

    /**
     * @param  list<array{status: string}>  $checks
     */
    private function overallStatus(array $checks): string {
        $statuses = array_column($checks, 'status');

        if (array_intersect(['failed', 'crashed'], $statuses) !== []) {
            return 'failed';
        }

        return in_array('warning', $statuses, true) ? 'warning' : 'ok';
    }
}
