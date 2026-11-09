<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Commands;

use Illuminate\Console\Command;
use SciFY\LaravelHealthEndpoint\CheckRegistry;
use Spatie\Health\Checks\Checks\ScheduleCheck;

/**
 * Writes the heartbeat that the "schedule" check reads. Spatie's own command only sees checks registered through
 * Health::checks(), which this package does not use.
 */
final class ScheduleHeartbeatCommand extends Command {
    protected $signature = 'health-endpoint:schedule-heartbeat';

    protected $description = 'Write the heartbeat for the /health schedule check';

    public function handle(CheckRegistry $registry): int {
        $check = $registry->scheduleCheck();

        // Not an error: a cron entry that outlives the check must not fill the log.
        if (! $check instanceof ScheduleCheck) {
            return self::SUCCESS;
        }

        cache()->store($check->getCacheStoreName())->set($check->getCacheKey(), now()->timestamp);

        return self::SUCCESS;
    }
}
