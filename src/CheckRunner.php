<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;
use Throwable;

/**
 * Runs health checks live, without result stores or notifications.
 *
 * Follows the idea of Spatie's RunHealthChecksCommand::runCheck() (spatie/laravel-health, MIT,
 * copyright Spatie bvba), but catches Throwable instead of Exception.
 */
final class CheckRunner {
    /**
     * @param  list<Check>  $checks
     *
     * @return list<CheckOutcome>
     */
    public function run(array $checks): array {
        return array_map($this->runCheck(...), $checks);
    }

    private function runCheck(Check $check): CheckOutcome {
        if (! $check->shouldRun()) {
            return new CheckOutcome($check->getName(), new Result(Status::skipped()), 0);
        }

        $startedAt = microtime(true);

        try {
            $result = $check->run();
        } catch (Throwable $throwable) {
            // Full details go to the log and the error tracker; the response only says "Check crashed".
            report($throwable);
            $result = $check->markAsCrashed();
        }

        return new CheckOutcome($check->getName(), $result, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
