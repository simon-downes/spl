<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use RuntimeException;

/**
 * Command Line Interface utilities.
 *
 * Provides functionality for CLI applications including argument parsing,
 * formatted output, user input, and command handling.
 */
class CLI {

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Parsed command line options (with dashes).
     *
     * @var array<string, mixed>
     */
    protected static array $options = [];

    /**
     * Positional arguments (non-option arguments).
     *
     * @var array<int, string>
     */
    protected static array $args = [];

    /**
     * Stream to read user input from; null means STDIN.
     *
     * @var resource|null
     */
    protected static $stdin = null;

    /**
     * Formatting tokens and their corresponding ANSI escape codes.
     *
     * @var array<string, string>
     */
    protected static array $tokens = [
        // Text colors
        'black'        => "\033[30m",
        'red'          => "\033[31m",
        'green'        => "\033[32m",
        'yellow'       => "\033[33m",
        'blue'         => "\033[34m",
        'magenta'      => "\033[35m",
        'cyan'         => "\033[36m",
        'white'        => "\033[37m",
        'bright_black' => "\033[90m",
        'bright_red'   => "\033[91m",
        'bright_green' => "\033[92m",
        'bright_yellow' => "\033[93m",
        'bright_blue'  => "\033[94m",
        'bright_magenta' => "\033[95m",
        'bright_cyan'  => "\033[96m",
        'bright_white' => "\033[97m",

        // Background colors
        'bg_black'     => "\033[40m",
        'bg_red'       => "\033[41m",
        'bg_green'     => "\033[42m",
        'bg_yellow'    => "\033[43m",
        'bg_blue'      => "\033[44m",
        'bg_magenta'   => "\033[45m",
        'bg_cyan'      => "\033[46m",
        'bg_white'     => "\033[47m",
        'bg_bright_black' => "\033[100m",
        'bg_bright_red'   => "\033[101m",
        'bg_bright_green' => "\033[102m",
        'bg_bright_yellow' => "\033[103m",
        'bg_bright_blue'  => "\033[104m",
        'bg_bright_magenta' => "\033[105m",
        'bg_bright_cyan'  => "\033[106m",
        'bg_bright_white' => "\033[107m",

        // Text styles
        'bold'         => "\033[1m",
        'dim'          => "\033[2m",
        'italic'       => "\033[3m",
        'underline'    => "\033[4m",
        'blink'        => "\033[5m",
        'reverse'      => "\033[7m",
        'hidden'       => "\033[8m",

        // Reset
        'reset'        => "\033[0m",
    ];

    /**
     * Parse command line arguments into a structured array.
     *
     * Supports the following formats:
     * - `-abc` - Single-letter boolean options
     * - `--foo-bar` - Multi-word boolean options
     * - `-foo=baz` - Single-word option with value
     * - `--foo-bar=baz` - Multi-word option with value
     */
    public static function parseArgs(array $argv): array {
        // Skip the first argument (script name)
        array_shift($argv);

        static::$options = [];
        static::$args = [];

        foreach ($argv as $arg) {
            // Pattern for --option=value or -option=value
            if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
                // Long option with value: --option=value
                static::$options[$matches[1]] = $matches[2];
            }
            elseif (preg_match('/^-([^=]+)=(.*)$/', $arg, $matches)) {
                // Short option with value: -option=value
                static::$options[$matches[1]] = $matches[2];
            }
            elseif (preg_match('/^--(.+)$/', $arg, $matches)) {
                // Long boolean option: --option
                static::$options[$matches[1]] = true;
            }
            elseif (preg_match('/^-([a-zA-Z0-9]+)$/', $arg, $matches)) {
                // Short boolean option(s): -a or -abc
                $options = str_split($matches[1]);
                foreach ($options as $option) {
                    static::$options[$option] = true;
                }
            }
            else {
                // Not an option, add to positional arguments
                static::$args[] = $arg;
            }
        }

        return static::$options;
    }

    /**
     * Check if a specific option exists.
     */
    public static function hasOption(string $name): bool {
        return array_key_exists($name, static::$options);
    }

    /**
     * Get the value of a specific option or the default if not found.
     */
    public static function getOption(string $name, mixed $default = null): mixed {
        return static::hasOption($name) ? static::$options[$name] : $default;
    }

    /**
     * Get all positional arguments (non-option arguments).
     */
    public static function getArgs(): array {
        return static::$args;
    }

    /**
     * Print a string with formatting tokens.
     *
     * Tokens are in the format [token_name] and will be replaced with the corresponding
     * ANSI escape code if colours are enabled, or removed if not.
     * Bracketed text that isn't a known token is left as is.
     */
    public static function print(string $text): void {
        echo static::processTokens($text, static::useColours(STDOUT));
    }

    /**
     * Print a string with formatting tokens followed by a newline.
     *
     * Tokens are in the format [token_name] and will be replaced with the corresponding
     * ANSI escape code if colours are enabled, or removed if not.
     * Bracketed text that isn't a known token is left as is.
     */
    public static function println(string $text): void {
        echo static::processTokens($text, static::useColours(STDOUT)), PHP_EOL;
    }

    /**
     * Determine if output written to a stream should use colours.
     *
     * Colours are used only when the stream is a terminal and the NO_COLOR environment
     * variable is unset or empty (https://no-color.org).
     *
     * @param resource $stream
     */
    protected static function useColours($stream): bool {
        // read the raw value: any non-empty string (even "0" or "false") disables colours
        $no_color = getenv('NO_COLOR');

        if (is_string($no_color) && $no_color !== '') {
            return false;
        }

        return stream_isatty($stream);
    }

    /**
     * Replace known formatting tokens with ANSI escape codes, or remove them if colours are disabled.
     *
     * Bracketed text that isn't a known token is returned unchanged.
     */
    protected static function processTokens(string $text, bool $colours): string {
        return (string) preg_replace_callback(
            '/\[([a-z_]+)\]/',
            function (array $matches) use ($colours): string {
                $token = $matches[1];

                if (!isset(static::$tokens[$token])) {
                    return $matches[0];
                }

                return $colours ? static::$tokens[$token] : '';
            },
            $text,
        );
    }

    /**
     * Wrap literal text in a single formatting token.
     *
     * The text itself is not searched for tokens, so data such as option keys and
     * default values is always shown as is.
     */
    protected static function style(string $text, string $token, bool $colours): string {
        if (!$colours) {
            return $text;
        }

        return static::$tokens[$token] . $text . static::$tokens['reset'];
    }

    /**
     * Read a line from STDIN with surrounding whitespace removed.
     *
     * Returns null at end of input (or if STDIN can't be read), after printing a newline
     * so following output doesn't continue on the prompt's line.
     */
    protected static function readLine(): ?string {
        $line = fgets(static::$stdin ?? STDIN);

        if ($line === false) {
            echo PHP_EOL;
            return null;
        }

        return trim($line);
    }

    /**
     * Prompt the user for input.
     *
     * Returns the default if the input is empty or STDIN has reached end of input.
     */
    public static function prompt(string $prompt, string $default = ''): string {
        $colours = static::useColours(STDOUT);

        $text = static::processTokens($prompt, $colours);

        // Add default value to prompt if provided - shown literally, not as formatting tokens
        if ($default !== '') {
            $text .= ' [' . static::style($default, 'bright_cyan', $colours) . ']';
        }

        echo "{$text}: ";

        $input = static::readLine();

        // Return default if input is empty or there is no more input
        if ($input === null || $input === '') {
            return $default;
        }

        return $input;
    }

    /**
     * Ask the user for confirmation (yes/no).
     *
     * Returns the default if the input is empty or STDIN has reached end of input.
     */
    public static function confirm(string $prompt, bool $default = true): bool {
        $yes = $default ? '[bright_cyan]Y[reset]' : 'y';
        $no = $default ? 'n' : '[bright_cyan]N[reset]';

        $input = static::prompt("{$prompt} ({$yes}/{$no})");

        if ($input === '') {
            return $default;
        }

        return strtolower($input[0]) === 'y';
    }

    /**
     * Ask the user to select from a list of options.
     *
     * Asks again after an invalid choice. Returns the default if the input is empty, or if
     * STDIN reaches end of input and a default was given.
     *
     * @param array<int|string, string> $options Array of options to choose from
     *
     * @throws RuntimeException If STDIN reaches end of input and there is no default
     */
    public static function select(string $prompt, array $options, mixed $default = null): mixed {
        static::println($prompt);

        // compare as strings - input is always a string but option keys may be integers
        $keys = array_map('strval', array_keys($options));

        $colours = static::useColours(STDOUT);

        // Display options - keys are shown literally, not as formatting tokens
        foreach ($options as $key => $value) {
            $key_text = ($key === $default) ? static::style("{$key}", 'bright_cyan', $colours) : "{$key}";
            echo "  [{$key_text}] ", static::processTokens("{$value}", $colours), PHP_EOL;
        }

        // Add default to prompt if provided
        $default_text = $default !== null ? ' [' . static::style("{$default}", 'bright_cyan', $colours) . ']' : '';

        while (true) {
            echo "Enter choice{$default_text}: ";

            $input = static::readLine();

            // no more input so we can't ask again
            if ($input === null) {
                if ($default === null) {
                    throw new RuntimeException('Unexpected end of input');
                }

                return $default;
            }

            // Return default if input is empty and default is provided
            if ($input === '' && $default !== null) {
                return $default;
            }

            // Check if input is a valid option
            if (in_array($input, $keys, true)) {
                return $input;
            }

            static::println("[red]Invalid choice. Please try again.[reset]");
        }
    }

    /**
     * Handle command line arguments and execute the appropriate command.
     *
     * @param array<int, string> $argv Command line arguments (typically $argv)
     */
    public static function handle(array $argv): void {
        // Parse arguments
        static::parseArgs($argv);

        // Get command name (first positional argument)
        $command_name = static::$args[0] ?? 'help';

        // Load commands from config file
        $commands = static::loadCommands();

        // Check if command exists
        if (!isset($commands[$command_name])) {
            static::println("[red]Error: Unknown command '{$command_name}'[reset]");
            static::println("Run with 'help' to see available commands.");
            exit(1);
        }

        // Get command callable
        $callable = $commands[$command_name];

        // Remove command name from arguments
        array_shift(static::$args);

        // Execute command
        call_user_func($callable, static::$options, static::$args);
    }

    /**
     * Load commands from config/commands.php file.
     *
     * @return array<string, callable> Array of command names and their callables
     */
    protected static function loadCommands(): array {
        $commands_file = SPL_ROOT . '/config/commands.php';

        if (!file_exists($commands_file)) {
            throw new RuntimeException("Commands file not found: {$commands_file}");
        }

        $commands = require $commands_file;

        if (!is_array($commands)) {
            throw new RuntimeException("Commands file must return an array");
        }

        return $commands;
    }
}
