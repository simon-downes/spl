<?php declare(strict_types=1);

namespace spl\tests\web\fixtures;

use spl\web\App;
use spl\web\Request;
use spl\web\Response;

/**
 * Exposes App's protected routing steps to tests without SPL_ROOT, SPL::error() or a real send.
 *
 * Redeclares $routes so registered routes never leak into App itself.
 */
final class TestableApp extends App {

    /**
     * @var array<string, array<string, callable|string>>
     */
    protected static array $routes = [];

    public static function reset(): void {
        static::$routes = [];
    }

    /**
     * @param array<string, callable|string> $routes
     */
    public static function add(array $routes): void {
        static::addRoutes($routes);
    }

    public static function load(string $file): void {
        static::loadRoutes($file);
    }

    /**
     * @return array<string, array<string, callable|string>>
     */
    public static function routes(): array {
        return static::$routes;
    }

    /**
     * @return array{0: callable|string, 1: array<int|string, string>}
     */
    public static function matchRoute(string $path, string $method): array {
        return static::match($path, $method);
    }

    public static function dispatchRequest(Request $request): Response {
        return static::dispatch($request);
    }

    public static function respondTo(Request $request, Response $response): void {
        static::respond($request, $response);
    }

}
