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
        'bright_yellow'=> "\033[93m",
        'bright_blue'  => "\033[94m",
        'bright_magenta'=> "\033[95m",
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
        'bg_bright_yellow'=> "\033[103m",
        'bg_bright_blue'  => "\033[104m",
        'bg_bright_magenta'=> "\033[105m",
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
     * Tokens are in the format [token_name] and will be replaced with
     * the corresponding ANSI escape code if colors are enabled.
     */
    public static function print(string $text): void {
        echo static::processTokens($text);
    }

    /**
     * Print a string with formatting tokens followed by a newline.
     * 
     * Tokens are in the format [token_name] and will be replaced with
     * the corresponding ANSI escape code if colors are enabled.
     */
    public static function println(string $text): void {
        echo static::processTokens($text), PHP_EOL;
    }

    /**
     * Process formatting tokens in a string.
     */
    protected static function processTokens(string $text): string {
        // Check if output is a TTY (terminal)
        $use_colors = posix_isatty(STDOUT);

        if (!$use_colors) {
            // Remove all tokens if colors are disabled
            return preg_replace('/\[[a-z_]+\]/', '', $text);
        }

        // Replace tokens with ANSI escape codes
        return preg_replace_callback(
            '/\[([a-z_]+)\]/',
            function($matches) {
                $token = $matches[1];
                return isset(static::$tokens[$token]) ? static::$tokens[$token] : $matches[0];
            },
            $text
        );
    }

    /**
     * Prompt the user for input.
     */
    public static function prompt(string $prompt, string $default = ''): string {
        // Add default value to prompt if provided
        if ($default !== '') {
            $prompt .= " [[bright_cyan]{$default}[reset]]";
        }
        
        static::print("{$prompt}: ");
        
        $input = trim(fgets(STDIN));
        
        // Return default if input is empty
        if ($input === '') {
            return $default;
        }
        
        return $input;
    }

    /**
     * Ask the user for confirmation (yes/no).
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
     * @param array<int|string, string> $options Array of options to choose from
     */
    public static function select(string $prompt, array $options, mixed $default = null): mixed {
        static::println($prompt);
        
        $keys = array_keys($options);
        
        // Display options
        foreach ($options as $key => $value) {
            $marker = ($key === $default) ? '[bright_cyan]' : '';
            $reset = ($key === $default) ? '[reset]' : '';
            static::println("  [{$marker}{$key}{$reset}] {$value}");
        }
        
        // Add default to prompt if provided
        $default_text = $default !== null ? " [[bright_cyan]{$default}[reset]]" : '';
        
        while (true) {
            static::print("Enter choice{$default_text}: ");
            
            $input = trim(fgets(STDIN));
            
            // Return default if input is empty and default is provided
            if ($input === '' && $default !== null) {
                return $default;
            }
            
            // Check if input is a valid option
            if (in_array($input, $keys)) {
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
