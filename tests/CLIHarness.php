<?php declare(strict_types=1);

namespace spl\tests;

use spl\CLI;

/**
 * Exposes the CLI class's protected test seams and lets tests choose which streams count as colour terminals.
 */
final class CLIHarness extends CLI {

    /**
     * Streams to treat as colour terminals; null uses the real check in CLI::useColours().
     *
     * @var array<int, resource>|null
     */
    public static ?array $terminals = null;

    /**
     * @param resource $stream
     */
    protected static function useColours($stream): bool {
        return static::$terminals === null ? parent::useColours($stream) : in_array($stream, static::$terminals, true);
    }

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
