<?php declare(strict_types=1);

namespace spl\tests;

use spl\CLI;

/**
 * Exposes the CLI class's protected test seams.
 */
final class CLIHarness extends CLI {

    /**
     * Replace the input stream; call with no arguments to restore STDIN.
     *
     * @param resource|null $stdin
     */
    public static function setStreams($stdin = null): void {
        static::$stdin = $stdin;
    }

}
