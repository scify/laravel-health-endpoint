<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Commands;

use Illuminate\Console\Command;
use SciFY\LaravelHealthEndpoint\CheckRegistry;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Jobs\HealthQueueJob;

/**
 * Dispatches the job whose worker writes the heartbeat that the "queue" check reads. The job carries the package's
 * QueueCheck, so the worker writes the package's cache key.
 */
final class QueueHeartbeatCommand extends Command {
    protected $signature = 'health-endpoint:queue-heartbeat';

    protected $description = 'Dispatch the heartbeat job for the /health queue check';

    public function handle(CheckRegistry $registry): int {
        $check = $registry->queueCheck();

        if (! $check instanceof QueueCheck) {
            return self::SUCCESS;
        }

        foreach ($check->getQueues() as $queue) {
            dispatch(new HealthQueueJob($check))->onQueue($queue);
        }

        return self::SUCCESS;
    }
}
