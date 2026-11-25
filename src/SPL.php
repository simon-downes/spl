<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use DateTimeInterface;
use ErrorException;
use Throwable;
use LogicException;

use spl\util\Config;
use spl\util\Debug;
use spl\util\Env;
use spl\web\Request;
use spl\web\App as WebApp;
use spl\CLI;

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
     * Error types that stop the script and can only be seen from a shutdown function.
     */
    protected const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /**
     * Set while error() is handling an error, so a failure inside it isn't handled recursively.
     */
    protected static bool $handling = false;

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Initializes the framework environment.
     *
     * Sets SPL_ROOT and SPL_DEBUG constants and loads environment variables.
     * SPL_DEBUG is determined from APP_DEBUG or APP_ENV environment variables.
     * A relative APP_LOG_FILE is resolved against SPL_ROOT and becomes the default log destination.
     *
     * Registers handlers so uncaught exceptions and fatal errors go to error() and, in debug mode,
     * PHP warnings, notices and deprecations are thrown as ErrorException.
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

        // not an absolute path so make it relative to the root directory ('php' means error_log())
        if ($log_file && $log_file != 'php' && substr($log_file, 0, 1) != '/') {
            $log_file = SPL_ROOT . '/' . $log_file;
        }

        Log::setFile($log_file);

        // in debug mode PHP warnings, notices and deprecations become exceptions
        if (SPL_DEBUG) {
            set_error_handler([static::class, 'handleError']);
        }

        set_exception_handler([static::class, 'handleException']);

        register_shutdown_function([static::class, 'handleShutdown']);

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
                CLI::handle($GLOBALS['argv']);
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
     * The error is logged at ERROR level. For web requests an error page is displayed.
     * For CLI requests the formatted error is also written to STDERR, unless the log already
     * went there (error_log() with no error_log ini setting).
     *
     * If the log can't be written the error goes to error_log() instead. If handling the error
     * fails, or an error occurs while one is being handled, a one-line summary goes to error_log().
     *
     * Will exit the application with the provided exit code unless exit is 0.
     */
    public static function error(Throwable $error, int $exit = 1): void {

        // something failed while we were handling an error so keep it simple
        if (static::$handling) {
            error_log(static::summary($error));
        }
        else {

            static::$handling = true;

            try {
                static::report($error);
            }
            catch (Throwable $e) {
                error_log(static::summary($error) . ' (error handling failed: ' . static::summary($e) . ')');
            }
            finally {
                static::$handling = false;
            }

        }

        if ($exit) {
            exit($exit);
        }

    }

    /**
     * Error handler registered by init() in debug mode.
     *
     * Throws errors included in error_reporting() as ErrorException. Others, including those
     * suppressed with @, are left to PHP's standard handling.
     *
     * @internal
     *
     * @throws ErrorException
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool {

        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);

    }

    /**
     * Exception handler registered by init(); passes uncaught exceptions to error().
     *
     * @internal
     */
    public static function handleException(Throwable $error): void {

        static::error($error);

    }

    /**
     * Shutdown function registered by init(); passes fatal errors (out of memory, time limit
     * exceeded, etc.) to error() as an ErrorException.
     *
     * Doesn't exit, so PHP keeps its exit status and later shutdown functions still run.
     *
     * @internal
     */
    public static function handleShutdown(): void {

        $error = error_get_last();

        if ($error === null || !($error['type'] & static::FATAL_ERRORS)) {
            return;
        }

        static::error(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), 0);

    }

    /**
     * Logs an error and outputs it to the user (error page or STDERR).
     */
    protected static function report(Throwable $error): void {

        $trace = (new Debug())->toString($error);

        $to_error_log = Log::usesErrorLog('ERROR');

        try {
            Log::error($trace);
        }
        catch (Throwable $e) {
            // couldn't write to the log so fall back to PHP's error log
            error_log($trace);
            error_log('Unable to write log: ' . $e->getMessage());
            $to_error_log = true;
        }

        // web pages need to return something to the user
        if (!SPL_CLI) {
            require __DIR__ . '/error.php';
        }
        // in the CLI, error_log() writes to the terminal when the error_log ini setting is empty
        elseif (!$to_error_log || ini_get('error_log') !== '') {
            fwrite(STDERR, $trace . "\n");
        }

    }

    /**
     * Returns a one-line description of an error.
     */
    protected static function summary(Throwable $error): string {

        return sprintf('%s: %s in %s:%d', get_class($error), $error->getMessage(), $error->getFile(), $error->getLine());

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
