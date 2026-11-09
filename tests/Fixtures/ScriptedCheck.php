<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Tests\Fixtures;

use Closure;
use LogicException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * An app check whose result each test scripts by name. Registered through extra_checks, like a real app check.
 */
final class ScriptedCheck extends Check {
    /**
     * @var array<string, Closure(): Result>
     */
    private static array $scripts = [];

    /**
     * @param  Closure(): Result  $run
     */
    public static function script(string $name, Closure $run): void {
        self::$scripts[$name] = $run;
    }

    public static function reset(): void {
        self::$scripts = [];
    }

    public function run(): Result {
        $script = self::$scripts[$this->getName()] ?? throw new LogicException("No script for [{$this->getName()}].");

        return $script();
    }
}
