<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Orchestra\Testbench\TestCase as Orchestra;
use SciFY\LaravelHealthEndpoint\HealthEndpointServiceProvider;
use SciFY\LaravelHealthEndpoint\Tests\Fixtures\ScriptedCheck;
use SciFY\LaravelHealthEndpoint\Tests\Fixtures\ScriptedProbe;
use Spatie\Health\HealthServiceProvider;

abstract class TestCase extends Orchestra {
    protected function setUp(): void {
        parent::setUp();

        ScriptedCheck::reset();
        ScriptedProbe::$answer = null;
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array {
        return [HealthServiceProvider::class, HealthEndpointServiceProvider::class];
    }

    /**
     * Asserts that a /health response body matches the shared contract.
     */
    protected function assertMatchesHealthSchema(string $json): void {
        $validator = new Validator;
        $schema = (string) file_get_contents(__DIR__ . '/../resources/schema/health-response.schema.json');
        $error = $validator->validate(json_decode($json), $schema)->error();

        $this->assertNull(
            $error,
            $error instanceof ValidationError ? (string) json_encode((new ErrorFormatter)->format($error), JSON_PRETTY_PRINT) : '',
        );
    }
}
