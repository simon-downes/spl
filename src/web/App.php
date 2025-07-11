<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use Throwable;

use spl\SPL;
use spl\util\Config;

/**
 * Simple web application router and request handler.
 *
 * Routes requests to handlers based on regex path matching.
 * Supports method-specific routes and automatic response conversion.
 */
class App {

    /**
     * The registered routes.
     *
     * @var array<string, array<string, callable|string>>
     */
    protected static array $routes = [];

    /**
     * Processes a web request by matching routes and executing handlers.
     *
     * Automatically converts handler return values to Response objects:
     * - Strings are converted to HTML responses
     * - Arrays are converted to JSON responses
     * - Response objects are used as-is
     */
    public static function handle(Request $request): void {

        try {

            static::loadRoutes(SPL_ROOT . '/config/routes.php');

            // get a handler and some parameters to use
            list($handler, $args) = static::match($request->getPath(), $request->getMethod());

            // if handler is a string then we assume it to be an invokable class
            // in which case we need to instantiate it - via container or directly
            if (is_string($handler)) {

                // TODO: attempt to resolve from container - when we add one

                // otherwise assume it's a class and instantiate it
                $handler = new $handler();

            }

            // we pass the request and response in addition to the route arguments
            $args = array_merge([$request], $args);

            // call the handler to get the desired response
            $response = call_user_func($handler, ...$args);

            // if the response is a string then assume it's html
            if (is_string($response)) {
                $response = Response::html($response);
            }
            // if the response is an array then convert it to json
            elseif (is_array($response)) {
                $response = Response::json($response);
            }

        }
        catch (Throwable $e) {

            $response = Response::fromThrowable($e);

            // TODO: render a view based on response code

            // for server errors display the default
            if ($response->isServerError()) {
                SPL::error($e);
            }

        }

        $response->send();

    }

    /**
     * Loads routes from a PHP file that returns an array of route definitions.
     *
     * Supports method-specific routes with syntax: "(METHOD1|METHOD2):path"
     */
    protected static function loadRoutes(string $file): void {

        $routes = require($file);

        foreach ($routes as $regex => $handler) {

            $methods = [];

            if (preg_match('/^([A-z| ]+):(.*)/i', $regex, $m)) {
                $methods = explode('|', trim($m[1], '()'));
                $regex   = $m[2];
            }

            $current = static::$routes[$regex] ?? [];

            static::$routes[$regex] = $current + array_fill_keys($methods, $handler);

        }

    }

    /**
     * Matches a request path against available routes.
     *
     * @return array{0: callable|string, 1: array} [handler, parameters]
     *
     * @throws WebException If no route matches or the method is not allowed
     */
    protected static function match(string $path, string $method): array {

        foreach (static::$routes as $regex => $handlers) {

            if (preg_match(";^{$regex}$;", $path, $parameters)) {

                $handler = $handlers[$method] ?? false;

                if (!$handler) {
                    throw WebException::methodNotAllowed($method, $path);
                }

                // first element is the complete string, we only care about the sub-matches
                array_shift($parameters);

                return [$handler, $parameters];

            }

        }

        throw WebException::notFound($path);

    }

}
