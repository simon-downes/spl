<?php declare(strict_types=1);

namespace spl\tests;

use spl\CLI;

/**
 * Exposes the CLI class's protected test seams.
 */
final class CLIHarness extends CLI {

    /**
     * Replace the input and error streams; call with no arguments to restore STDIN and STDERR.
     *
     * @param resource|null $stdin
     * @param resource|null $stderr
     */
    public static function setStreams($stdin = null, $stderr = null): void {
        static::$stdin = $stdin;
        static::$stderr = $stderr;
    }

    public static function dispatch(array $argv, string $commands_file): int {
        return parent::dispatch($argv, $commands_file);
    }

}
