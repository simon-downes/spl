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
 * Main SPL framework class.
 * 
 * Provides core functionality for the SPL framework.
 */
class SPL {

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Initialize the framework.
     *
     * Sets up the SPL_ROOT and SPL_DEBUG constants and loads environment variables.
     *
     * @param string $directory The root directory of the application
     * @param bool   $load_env  Whether to load environment variables from .env file
     * 
     * @return void
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
            '' => !(bool) preg_match('/^prod/i',  (string) env('APP_ENV', 'dev')),

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
     * Return the value of a configuration setting.
     *
     * @param string $key     The configuration key
     * @param mixed  $default The default value if the key doesn't exist
     * 
     * @return mixed The configuration value
     */
    public static function config(string $key, mixed $default = null): mixed {

        static $config;

        if (empty($config)) {
            $config = Config::safeLoad(SPL_ROOT . '/config/config.php');
        }

        return $config->get($key, $default);

    }

    /**
     * Dump a variable to StdOut.
     *
     * @param mixed $var The variable to dump
     * 
     * @return void
     */
    public static function dump(mixed $var): void {

        echo (new Debug())->toString($var), "\n";

    }

    /**
     * Render a Twig template with the specified context.
     *
     * @param string $template The template name
     * @param array  $context  The template context variables
     * 
     * @return string The rendered template
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
     * Run the application.
     *
     * @param string $directory The root directory of the application
     * 
     * @return void
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
     * Handle an error.
     *
     * Logs the error and displays an error page for web requests.
     *
     * @param Throwable $error The error to handle
     * @param int       $exit  The exit code (0 to not exit)
     * 
     * @return void
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
     * Convert various time formats to a Unix timestamp.
     *
     * @param int|string|DateTimeInterface $time The time to convert
     * 
     * @return int The Unix timestamp
     * 
     * @throws LogicException If the time cannot be converted to a timestamp
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
