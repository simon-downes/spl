<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use DateTimeInterface;
use Throwable;
use LogicException;

use spl\util\Config;
use spl\util\Debug;
use spl\util\Env;
use spl\web\Request;
use spl\web\App as WebApp;

use Twig\Loader\FilesystemLoader as TwigLoader;
use Twig\Environment as TwigEnvironment;

/**
 * Core framework functionality and utilities.
 *
 * Provides initialization, configuration, error handling, and template rendering.
 * Acts as the central entry point for the SPL framework.
 */
class SPL {

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Initializes the framework environment.
     *
     * Sets SPL_ROOT and SPL_DEBUG constants and loads environment variables.
     * SPL_DEBUG is determined from APP_DEBUG or APP_ENV environment variables.
     */
    public static function init(string $directory = '', bool $load_env = true): void {

        // we've already run init so nothing to do
        if (defined('SPL_ROOT')) {
            return;
        }

        define('SPL_ROOT', $directory ? realpath($directory) : getcwd());

        // load environment variables from .env if specified and file exists
        $load_env && Env::safeLoad(SPL_ROOT . '/.env');

        define('SPL_DEBUG', match (env('APP_DEBUG', '')) {

            // explicitly set so use that
            true => true,
            false => false,

            // not defined or defined as an empty string so enable for non-production environments
            '' => !(bool) preg_match('/^prod/i', (string) env('APP_ENV', 'dev')),

            // defined as an unknown string so set to false for safety
            default => false,

        });

        $log_file = (string) env('APP_LOG_FILE', '');

        // not an absolute path so make it relative to the root directory
        if ($log_file && substr($log_file, 0, 1) != '/') {
            $log_file = SPL_ROOT . '/' . $log_file;
        }

    }

    /**
     * Retrieves configuration values from config/config.php.
     *
     * Lazily loads the configuration file on first use.
     */
    public static function config(string $key, mixed $default = null): mixed {

        static $config;

        if (empty($config)) {
            $config = Config::safeLoad(SPL_ROOT . '/config/config.php');
        }

        return $config->get($key, $default);

    }

    /**
     * Dumps a variable to stdout in a readable format.
     */
    public static function dump(mixed $var): void {

        echo (new Debug())->toString($var), "\n";

    }

    /**
     * Renders a Twig template with the provided context.
     *
     * Lazily initializes Twig environment on first use.
     * Template directory is configured via twig.templates config (defaults to /templates).
     */
    public static function render(string $template, array $context = []): string {

        static $twig;

        if (empty($twig)) {

            $twig = new TwigEnvironment(
                new TwigLoader((string) static::config('twig.templates', SPL_ROOT . '/templates')),
                [
                    'cache'       => SPL::config('twig.cache', false),
                    'debug'       => SPL_DEBUG,
                    'auto_reload' => true,
                ],
            );
        }

        return $twig->render($template, $context);

    }

    /**
     * Runs the application, handling web or CLI requests appropriately.
     *
     * Initializes the framework and catches any uncaught exceptions.
     */
    public static function run(string $directory): void {

        try {

            static::init($directory);

            if (SPL_CLI) {

            }
            else {

                WebApp::handle(Request::fromGlobals());

            }

        }
        catch (Throwable $e) {

            static::error($e);

        }

    }

    /**
     * Handles errors by logging them and displaying appropriate output.
     *
     * For CLI requests, only logs the error.
     * For web requests, displays an error page and logs the error.
     *
     * Will exit the application with the provided exit code unless exit is 0.
     */
    public static function error(Throwable $error, int $exit = 1): void {

        $trace = (new Debug())->toString($error);

        // log the error
        Log::error($trace);

        // for cli scripts we've already logged the error to the correct place
        // but for web pages we need to return something to the user
        if (!SPL_CLI) {
            require __DIR__ . '/error.php';
        }

        if ($exit) {
            exit($exit);
        }

    }

    /**
     * Converts various time formats to a Unix timestamp.
     *
     * Handles integers, DateTimeInterface objects, and string dates.
     * Empty string returns the current timestamp.
     *
     * @throws LogicException If the time string cannot be parsed
     */
    public static function makeTimestamp(int|string|DateTimeInterface $time): int {

        if (is_numeric($time)) {
            return (int) $time;
        }
        elseif ($time instanceof DateTimeInterface) {
            return $time->getTimestamp();
        }
        elseif ($time === '') {
            return time();
        }

        $ts = strtotime($time);

        if ($ts === false) {
            throw new LogicException("Unable convert {$time} to a valid timestamp");
        }

        return $ts;

    }

}
