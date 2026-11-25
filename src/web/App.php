<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use InvalidArgumentException;
use JsonSerializable;
use Throwable;
use UnexpectedValueException;

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
     * Matches a route key: a method prefix ("GET", "GET|POST" or "(GET|POST)"), a colon and
     * the path regex.
     */
    protected const ROUTE_KEY_REGEX = '/^(?:\(([A-Z]+(?:\|[A-Z]+)*)\)|([A-Z]+(?:\|[A-Z]+)*)):(.*)$/sD';

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
     * - Arrays and JsonSerializable objects are converted to JSON responses
     * - null is converted to a 204 No Content response
     * - Response objects are used as-is
     * - Anything else is an error (500)
     *
     * A HEAD request is answered by the route's GET handler unless it has its own HEAD handler;
     * the status and headers are sent without the body.
     */
    public static function handle(Request $request): void {

        try {

            static::loadRoutes(SPL_ROOT . '/config/routes.php');

            $response = static::dispatch($request);

        }
        catch (Throwable $e) {

            $response = Response::fromThrowable($e);

            // TODO: render a view based on response code

            // for server errors display the default
            if ($response->isServerError()) {
                SPL::error($e);
            }

        }

        static::respond($request, $response);

    }

    /**
     * Matches the request to a route, calls its handler and converts the result to a Response.
     *
     * @throws WebException If no route matches or the method is not allowed
     * @throws UnexpectedValueException If the handler returned an unsupported type
     */
    protected static function dispatch(Request $request): Response {

        // get a handler and some parameters to use
        list($handler, $args) = static::match($request->getPath(), $request->getMethod());

        // if handler is a string then we assume it to be an invokable class
        // in which case we need to instantiate it - via container or directly
        if (is_string($handler)) {

            // TODO: attempt to resolve from container - when we add one

            // otherwise assume it's a class and instantiate it
            $handler = new $handler();

        }

        // we pass the request in addition to the route arguments
        // (string-keyed arguments become named arguments)
        return static::toResponse(call_user_func($handler, $request, ...$args), $request);

    }

    /**
     * Converts a handler's return value to a Response.
     *
     * @throws UnexpectedValueException If the handler returned an unsupported type
     */
    protected static function toResponse(mixed $result, Request $request): Response {

        if ($result instanceof Response) {
            return $result;
        }
        // if the response is a string then assume it's html
        elseif (is_string($result)) {
            return Response::html($result);
        }
        // if the response is an array then convert it to json
        elseif (is_array($result)) {
            return Response::json($result);
        }
        // nothing to return; 204 responses must not have content headers
        elseif ($result === null) {
            return (new Response(204))
                ->setHeader('content-type', null)
                ->setHeader('content-length', null);
        }
        // serialise the object itself, jsonSerialize() may return any JSON value
        elseif ($result instanceof JsonSerializable) {
            return new Response(200, json_encode($result, JSON_THROW_ON_ERROR), 'application/json');
        }

        throw new UnexpectedValueException(sprintf(
            'Route handler for %s %s returned unsupported type %s; expected string, array, null, %s or %s',
            $request->getMethod(),
            $request->getPath(),
            get_debug_type($result),
            JsonSerializable::class,
            Response::class,
        ));

    }

    /**
     * Sends the response to the client.
     *
     * HEAD requests get the status and headers (including the content-length of the body that
     * GET would return) but no body.
     */
    protected static function respond(Request $request, Response $response): void {

        if ($request->getMethod() !== 'HEAD') {
            $response->send();
            return;
        }

        // headers aren't output buffered, so this only discards the body
        ob_start();

        try {
            $response->send();
        }
        finally {
            ob_end_clean();
        }

    }

    /**
     * Loads routes from a PHP file that returns an array of route definitions.
     */
    protected static function loadRoutes(string $file): void {

        static::addRoutes(require($file));

    }

    /**
     * Registers an array of route definitions.
     *
     * Every route key must start with a method prefix, in one of these forms:
     * "GET:/path", "GET|POST:/path" or "(GET|POST):/path". Method names are uppercase letters.
     * The rest of the key is the path regex.
     *
     * @param array<array-key, callable|string> $routes
     *
     * @throws InvalidArgumentException If a route key has no valid method prefix
     */
    protected static function addRoutes(array $routes): void {

        foreach ($routes as $key => $handler) {

            $key = (string) $key;

            if (!preg_match(static::ROUTE_KEY_REGEX, $key, $m)) {
                throw new InvalidArgumentException(sprintf(
                    'Route "%s" has no valid method prefix; expected e.g. "GET:/path", "GET|POST:/path" or "(GET|POST):/path"',
                    $key,
                ));
            }

            // the methods are in group 1 when bracketed, otherwise in group 2
            $methods = explode('|', $m[1] !== '' ? $m[1] : $m[2]);
            $regex   = $m[3];

            $current = static::$routes[$regex] ?? [];

            static::$routes[$regex] = $current + array_fill_keys($methods, $handler);

        }

    }

    /**
     * Matches a request path against available routes.
     *
     * Captures are returned by name if the route regex has named groups, otherwise by position.
     *
     * @return array{0: callable|string, 1: array<int|string, string>} [handler, parameters]
     *
     * @throws WebException If no route matches or the method is not allowed
     */
    protected static function match(string $path, string $method): array {

        foreach (static::$routes as $regex => $handlers) {

            // unmatched groups are null so named groups are always present, matched or not
            if (preg_match(";^{$regex}$;", $path, $parameters, PREG_UNMATCHED_AS_NULL)) {

                // HEAD is answered by the GET handler unless the route has its own HEAD handler
                $handler = $handlers[$method] ?? ($method === 'HEAD' ? $handlers['GET'] ?? false : false);

                if (!$handler) {
                    throw WebException::methodNotAllowed($method, $path);
                }

                // first element is the complete string, we only care about the sub-matches
                array_shift($parameters);

                return [$handler, static::routeArguments($parameters)];

            }

        }

        throw WebException::notFound($path);

    }

    /**
     * Turns a route's captures into handler arguments.
     *
     * If the route has named groups only those are returned, keyed by name (named and positional
     * arguments can't be mixed); a named group that didn't match is left out so the handler's
     * default applies. Otherwise the captures are returned by position as PCRE reports them by
     * default: trailing unmatched groups are dropped and other unmatched groups are ''.
     *
     * @param array<int|string, string|null> $captures
     *
     * @return array<int|string, string>
     */
    protected static function routeArguments(array $captures): array {

        $named = array_filter($captures, 'is_string', ARRAY_FILTER_USE_KEY);

        if ($named) {
            return array_filter($named, fn(?string $value) => $value !== null);
        }

        while ($captures && end($captures) === null) {
            array_pop($captures);
        }

        return array_map(fn(?string $value) => $value ?? '', $captures);

    }

}
