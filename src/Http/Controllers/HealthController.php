<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Http\Controllers;

use Illuminate\Http\JsonResponse;
use SciFY\LaravelHealthEndpoint\CheckRegistry;
use SciFY\LaravelHealthEndpoint\CheckRunner;
use SciFY\LaravelHealthEndpoint\ResultMapper;

/**
 * GET /health: runs the registered checks live and returns the shared JSON contract.
 */
final class HealthController {
    public function __invoke(CheckRegistry $registry, CheckRunner $runner, ResultMapper $mapper): JsonResponse {
        $startedAt = microtime(true);
        $outcomes = $runner->run($registry->checks());

        $envelope = $mapper->toEnvelope(
            $outcomes,
            $registry->metaAllowList(),
            (int) round((microtime(true) - $startedAt) * 1000),
            now()->utc()->toIso8601ZuluString(),
            config()->string('app.name'),
            config()->string('app.env'),
        );

        return response()->json($envelope, $envelope['status'] === 'failed' ? 503 : 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
