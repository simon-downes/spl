# Simon's PHP/Prototyping Library AI Agent Technical Reference

> **For AI Agents Only**: This file contains technical implementation details for AI assistants.
> **Humans should refer to README.md** for complete deployment instructions and tutorials.

SPL (Simon's PHP/Prototyping Library) is a lightweight utility library for PHP 8+ applications designed for rapid prototyping. It provides a collection of helper classes for common tasks like database operations, logging, HTTP requests, web routing, and string manipulation. The library emphasizes simplicity and ease of use over comprehensive feature coverage, making it ideal for quick prototypes and small applications.

## Rules

1. Always initialize the framework with `SPL::init()` before using any functionality.
2. Respect the environment variables used for configuration, especially `APP_DEBUG` and `APP_ENV`.
3. Use the provided debugging functions (`d()` and `dd()`) instead of `var_dump()` or `print_r()`.
4. Database connections should be configured via the `DB_DSN` environment variable.
5. Log messages should use the appropriate log level methods (`Log::debug()`, `Log::info()`, etc.).
6. For web applications, always handle exceptions properly to prevent exposing sensitive information.
7. Use the `env()` function to access environment variables with proper type conversion.
8. Avoid direct instantiation of utility classes that use static methods.
9. When extending the library, follow the established patterns for consistency.
10. For web applications, use the provided Request and Response classes for proper HTTP handling.

## Installation and Requirements

### Requirements
- PHP 8.0 or higher
- PDO extension for database functionality
- cURL extension for HTTP requests
- mbstring extension for string manipulation

### Installation

```bash
composer require simon-downes/spl
```

## Configuration

The library uses environment variables for configuration. These can be loaded from a `.env` file using `SPL::init()`.

### Core Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_DEBUG` | Enable debug mode | `false` in production, `true` otherwise |
| `APP_ENV` | Application environment | `dev` |
| `APP_LOG_FILE` | Path to log file | `php` (uses error_log) |
| `APP_LOG_LEVEL` | Minimum log level to record | `INFO` |
| `DB_DSN` | Database connection string | - |

### Constants

The following constants are defined automatically:

| Constant | Description |
|----------|-------------|
| `SPL_CLI` | `true` if running in CLI environment, `false` otherwise |
| `SPL_REQUEST_ID` | Random 8-character hex string for request identification |
| `SPL_START_TIME` | Time the request started |
| `SPL_ROOT` | Root directory of the application (set by `SPL::init()`) |
| `SPL_DEBUG` | Debug mode flag (set by `SPL::init()`) |

## Architecture

SPL follows a simple architecture with these key concepts:

1. **Static Utility Classes**: Most functionality is provided through static methods on utility classes (e.g., `Log`, `Str`, `Random`).

2. **Facade Pattern**: The `DB` class acts as a facade to the underlying database connection.

3. **Environment-Based Configuration**: Configuration is primarily handled through environment variables.

4. **Simple Web MVC**: For web applications, the library provides basic routing, request handling, and response generation.

5. **Minimal Dependencies**: The library aims to have minimal external dependencies.

6. **Lazy Loading**: Resources like database connections are created only when needed.

7. **Error Handling**: Comprehensive error handling with detailed debug information when enabled.

## File Structure

```
/src
├── bootstrap.php         # Core bootstrap file that defines constants and helper functions
├── DB.php                # Database facade
├── error.php             # Error page template
├── Http.php              # HTTP client for making requests
├── Log.php               # Logging functionality
├── Random.php            # Random data generation utilities
├── SPL.php               # Core framework functionality
├── Str.php               # String manipulation utilities
├── database/
│   └── Connection.php    # PDO wrapper with enhanced functionality
├── util/
│   ├── Config.php        # Configuration container with dot notation
│   ├── Debug.php         # Debug utilities
│   ├── Env.php           # Environment variable handling
│   └── Timer.php         # Timing utilities
└── web/
    ├── App.php           # Web application router
    ├── Request.php       # HTTP request representation
    ├── Response.php      # HTTP response representation
    └── WebException.php  # HTTP error exceptions
```

## API

### SPL

The core framework class that provides initialization, configuration, error handling, and template rendering.

```php
// Class methods
public static function init(string $directory = '', bool $load_env = true): void
public static function config(string $key, mixed $default = null): mixed
public static function dump(mixed $var): void
public static function render(string $template, array $context = []): string
public static function run(string $directory): void
public static function error(Throwable $error, int $exit = 1): void
public static function makeTimestamp(int|string|DateTimeInterface $time): int
```

Example usage:
```php
// Initialize the framework
SPL::init(getcwd(), true);

// Get configuration value
$dbHost = SPL::config('database.host', 'localhost');

// Render a template
$html = SPL::render('home.twig', ['title' => 'Welcome']);

// Handle an error
try {
    // Some code that might throw an exception
} catch (Exception $e) {
    SPL::error($e);
}
```

### Log

Simple logging system with support for multiple log levels.

```php
// Class methods
public static function message(string $message, string $level = "INFO", string $file = ''): void
public static function debug(string $message, string $file = ''): void
public static function info(string $message, string $file = ''): void
public static function warning(string $message, string $file = ''): void
public static function error(string $message, string $file = ''): void
public static function critical(string $message, string $file = ''): void
```

Example usage:
```php
// Log messages at different levels
Log::debug("Debugging information");
Log::info("User logged in: {$username}");
Log::warning("Rate limit approaching for IP: {$ip}");
Log::error("Failed to connect to database: {$error}");
Log::critical("Application in inconsistent state, shutting down");

// Log to a specific file
Log::info("Processing batch job", "/var/log/batch.log");
```

### DB

Static facade for database operations that lazily creates a connection when needed.

```php
// Class methods (forwarded to Connection instance)
public static function query(string|PDOStatement $statement, array $params = []): PDOStatement
public static function execute(string|PDOStatement $statement, array $params = []): int
public static function getAll(string|PDOStatement $statement, array $params = []): array
public static function getAllMulti(string|PDOStatement $statement, array $params = []): array
public static function getAssoc(string|PDOStatement $statement, array $params = []): array
public static function getAssocMulti(string|PDOStatement $statement, array $params = []): array
public static function getRow(string|PDOStatement $statement, array $params = []): array
public static function getCol(string|PDOStatement $statement, array $params = []): array
public static function getOne(string|PDOStatement $statement, array $params = []): mixed
public static function begin(): bool
public static function commit(): bool
public static function rollback(): bool
public static function inTransaction(): bool
public static function insertId(string $name = ''): string
```

Example usage:
```php
// Query with parameters
$users = DB::getAll("SELECT * FROM users WHERE status = ?", ['active']);

// Get a single row
$user = DB::getRow("SELECT * FROM users WHERE id = ?", [1]);

// Get a single value
$count = DB::getOne("SELECT COUNT(*) FROM users");

// Transaction
DB::begin();
try {
    DB::execute("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'john@example.com']);
    DB::execute("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)", [DB::insertId(), 2]);
    DB::commit();
} catch (Exception $e) {
    DB::rollback();
    throw $e;
}
```

### database\Connection

Enhanced PDO wrapper with simplified query methods.

```php
// Class methods
public function __construct(string $url)
public function reconnect(): void
public function getDSN(): stdClass
public function prepare(string|PDOStatement $statement): PDOStatement
public function query(string|PDOStatement $statement, array $params = []): PDOStatement
public function execute(string|PDOStatement $statement, array $params = []): int
public function getAll(string|PDOStatement $statement, array $params = []): array
public function getAllMulti(string|PDOStatement $statement, array $params = []): array
public function getAssoc(string|PDOStatement $statement, array $params = []): array
public function getAssocMulti(string|PDOStatement $statement, array $params = []): array
public function getRow(string|PDOStatement $statement, array $params = []): array
public function getCol(string|PDOStatement $statement, array $params = []): array
public function getOne(string|PDOStatement $statement, array $params = []): mixed
public function rawExec(string $sql): int
public function begin(): bool
public function commit(): bool
public function rollback(): bool
public function inTransaction(): bool
public function insertId(string $name = ''): string
public function quote(mixed $value, int $type = PDO::PARAM_STR): string
public function quoteIdentifier(string $name): string
public function getLastError(): array
public function getInfo(): array
```

Example usage:
```php
// Create a connection directly
$db = new Connection('mysql://user:pass@localhost/mydb');

// Execute a query
$result = $db->query("SELECT * FROM users WHERE id = ?", [1]);

// Get all rows
$users = $db->getAll("SELECT * FROM users");

// Get a key-value map
$userEmails = $db->getAssoc("SELECT id, email FROM users");
```

### Random

Utility for generating random strings.

```php
// Class methods
public static function hex(int $length = 40): string
public static function string(int $length, string $allowed = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string
```

Example usage:
```php
// Generate a random hex string (cryptographically secure)
$token = Random::hex(32);

// Generate a random string with custom character set
$password = Random::string(12);
$code = Random::string(6, '0123456789');
```

### Str

String manipulation utilities.

```php
// Class methods
public static function parseURL(string $url, array $defaults = []): array|null
public static function buildURL(array $parts, bool $show_pw = false): string
public static function uncamelise(string $str): string
public static function slugify(string $str): string
public static function removeAccents(string $str): string
public static function ordinal(int $n): string
public static function sizeFormat(int $bytes, int $precision): string
public static function xssClean(string $str, string $charset = 'UTF-8'): string
public static function stripControlChars(string $str): string
public static function normaliseLineEndings(string $str): string
public static function pluralise(string $str): string
public static function singularise(string $str): string
public static function seconds(string $str): int
public static function date(int|string|DateTimeInterface $time = ''): string
public static function datetime(int|string|DateTimeInterface $time = ''): string
```

Example usage:
```php
// Parse a URL into components
$parts = Str::parseURL('https://user:pass@example.com:8080/path?query=value');

// Create a URL-friendly slug
$slug = Str::slugify('Hello World! This is a test');  // "hello-world-this-is-a-test"

// Convert singular to plural
$plural = Str::pluralise('category');  // "categories"

// Format a file size
$size = Str::sizeFormat(1024 * 1024 * 5, 2);  // "5.00 MB"

// Clean a string for XSS vulnerabilities
$safe = Str::xssClean($userInput);
```

### Http

Simple HTTP client for making requests.

```php
// Class methods
public static function get(string $url, array $headers = []): object
public static function post(string $url, array $headers = [], string|array $body = ''): object
public static function put(string $url, array $headers = [], string|array $body = ''): object
public static function delete(string $url, array $headers = []): object
public static function options(string $url, array $headers = []): object
public static function request(string $method, string $url, array $headers = [], string|array $body = ''): object
public static function parse_headers(array|string $headers): array
public static function parse_status_line(string $status_line): array
```

Example usage:
```php
// Make a GET request
$response = Http::get('https://api.example.com/users');

// Make a POST request with JSON body
$response = Http::post(
    'https://api.example.com/users',
    ['Content-Type: application/json'],
    json_encode(['name' => 'John', 'email' => 'john@example.com'])
);

// Access response properties
$statusCode = $response->status_code;
$body = $response->body;  // Automatically parsed if JSON
$headers = $response->headers;
```

### util\Config

Configuration container with dot notation support.

```php
// Class methods
public static function load(string $file): static
public static function safeLoad(string $file): static
public function __construct(protected array $data = [])
public function has(int|string $key): bool
public function all(): array
public function get(int|string $key, mixed $default = null): mixed
```

Example usage:
```php
// Load configuration from a file
$config = Config::load('/path/to/config.php');

// Access configuration values with dot notation
$dbHost = $config->get('database.host', 'localhost');
$port = $config->get('database.port', 3306);

// Check if a configuration key exists
if ($config->has('feature.enabled')) {
    // Feature is configured
}
```

### util\Env

Simple environment helper that can load env files and return environment variables.

```php
// Class methods
public static function all(): array
public static function get(string $var, mixed $default = null): mixed
public static function load(string $file): void
public static function safeLoad(string $file): void
```

Example usage:
```php
// Load environment variables from a file
Env::safeLoad('/path/to/.env');

// Get an environment variable with type conversion
$debug = Env::get('APP_DEBUG', false);
$port = Env::get('DB_PORT', 3306);

// Get all environment variables
$allVars = Env::all();
```

### util\Debug

Debug utilities for variable inspection and error handling.

```php
// Class methods
public function __construct()
public function toString(mixed $var): string
public function getNull(mixed $var): string
public function getBoolean(bool $var): string
public function getInteger(int $var): string
public function getFloat(float $var): string
public function getString(string $str): string
public function getArray(array $arr): string
public function getObject(object $obj): string
public function getThrowable(Throwable $e): string
public function getResource($resource): string
```

Example usage:
```php
// Create a debug instance
$debug = new Debug();

// Convert a variable to a detailed string representation
$output = $debug->toString($complexObject);

// Format an exception
$formatted = $debug->getThrowable($exception);
```

### util\Timer

Timer utility class for measuring elapsed time.

```php
// Class methods
public static function requestTime(): float
public static function start(string $label = 'start'): void
public static function stop(string $label = 'stop'): void
public static function reset(): void
public static function mark(string $label): void
public static function isRunning(): bool
public static function getElapsed(): float
public static function getTotalElapsed(): float
public static function getMarks(): array
```

Example usage:
```php
// Start timing an operation
Timer::start('database-query');

// Perform the operation
$results = DB::getAll("SELECT * FROM large_table");

// Stop timing and get elapsed time
Timer::stop('database-query');
$elapsed = Timer::getTotalElapsed();

// Add timing marks for performance tracking
Timer::mark('before-processing');
// Process data
Timer::mark('after-processing');

// Get all timing marks
$marks = Timer::getMarks();
```

### web\App

Simple web application router and request handler.

```php
// Class methods
public static function handle(Request $request): void
```

Example usage:
```php
// Handle an incoming request
App::handle(Request::fromGlobals());
```

### web\Request

HTTP request representation with convenient access methods.

```php
// Class methods
public static function fromGlobals(): static
public function __construct(string $method, string $host, string $path, array $query = [], mixed $body = '', array $headers = [], array $cookies = [], string $client_ip = '', array $attributes = [])
public function getHost(): string
public function getMethod(): string
public function getPath(): string
public function lookup(string $k, mixed $default = null): mixed
public function getBody(): mixed
public function getHeader(string $name): string
public function getCookie(string $name): string
public function getSourceIP(): string
public function getAttribute(string $name, mixed $default = null): mixed
public function setAttribute(string $name, mixed $value): void
```

Example usage:
```php
// Create a request from global variables
$request = Request::fromGlobals();

// Access request properties
$path = $request->getPath();
$method = $request->getMethod();
$userAgent = $request->getHeader('user-agent');
$id = $request->lookup('id', 0);

// Create a custom request
$request = new Request(
    'POST',
    'example.com',
    '/api/users',
    [],
    ['name' => 'John'],
    ['Content-Type' => 'application/json']
);
```

### web\Response

HTTP response representation with fluent interface for configuration.

```php
// Class methods
public static function fromThrowable(Throwable $e): static
public static function redirect(string $url, bool $permanent = false): static
public static function json(array $data, int $status = 200): static
public static function html(string $body, int $status = 200): static
public static function text(string $body, int $status = 200): static
public function __construct(int $status, string $body = '', string $content_type = 'text/html')
public function isRedirect(): bool
public function isServerError(): bool
public function setStatus(int $code, string $message = ''): static
public function setBody(string $body, string $content_type = 'text/html', string $charset = 'UTF-8'): static
public function setHeader(string $name, ?string $value = null): static
public function setCookie(string $name, ?string $value = null, int $expires = 0, string $path = '/', string $domain = ''): static
public function send(): void
```

Example usage:
```php
// Create HTML response
$response = Response::html('<h1>Hello World</h1>');

// Create JSON response
$response = Response::json(['status' => 'success', 'data' => $data]);

// Create redirect response
$response = Response::redirect('/login');

// Customize response
$response = new Response(200)
    ->setBody('Hello World', 'text/plain')
    ->setHeader('X-Custom-Header', 'value')
    ->setCookie('session', $sessionId, 3600);

// Send response to client
$response->send();
```

### web\WebException

HTTP error exceptions with appropriate status codes.

```php
// Class methods
public static function badRequest(string $message): static
public static function unauthorized(string $message): static
public static function forbidden(string $message): static
public static function notFound(string $path): static
public static function methodNotAllowed(string $method, string $path): static
public function __construct(string $message, int $code)
```

Example usage:
```php
// Throw HTTP errors
throw WebException::badRequest("Invalid input parameters");
throw WebException::unauthorized("Authentication required");
throw WebException::forbidden("Insufficient permissions");
throw WebException::notFound("/unknown/path");
throw WebException::methodNotAllowed("POST", "/users");
```

## Examples

### Simple Web Application

This example demonstrates a structured web application using SPL with multiple files:

#### .env - Environment Variable Definitions

```bash

APP_NAME="Example SPL Web Application"
APP_ENV=dev
APP_DEBUG=true

# if not set then uses error_log()
APP_LOG_FILE=logs/app.log
APP_LOG_LEVEL = info

DB_DSN=sqlite://localhost/db/app.db
```

#### public/index.php - Main Entrypoint

```php
<?php

// Require the Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use spl\SPL;

// Run the application using SPL's built-in runner
// This will:
// - Initialize the framework
// - Load environment variables
// - Handle web or CLI requests
// - Catch and display any uncaught exceptions
SPL::run(dirname(__DIR__));
```

#### config/routes.php - Route Definitions

```php
<?php
// config/routes.php - Route definitions
// This file should return an array of route patterns mapped to handlers

use spl\SPL;
use spl\web\Request;
use spl\web\Response;
use spl\web\WebException;
use spl\DB;

// Define the application routes
return [
    // Home page route
    '/' => function(Request $request) {
        // Render the home template with some data
        return SPL::render('home.html', [
            'title' => SPL::config('app.name', 'SPL Application'),
            'welcome_message' => 'Welcome to your SPL application!',
            'current_time' => date('Y-m-d H:i:s'),
            'debug_mode' => SPL_DEBUG ? 'enabled' : 'disabled'
        ]);
    },

    // API route to list users
    '/api/users' => function(Request $request) {
        // Return user data as JSON (automatically converted)
        return [
            'users' => DB::getAll("SELECT id, name, email FROM users"),
            'count' => DB::getOne("SELECT COUNT(*) FROM users")
        ];
    },

    // API route to get a specific user by ID
    '/api/users/(\d+)' => function(Request $request, $id) {
        $user = DB::getRow("SELECT * FROM users WHERE id = ?", [$id]);

        if (empty($user)) {
            throw WebException::notFound("User {$id} not found");
        }

        // Remove sensitive data before returning
        unset($user['password']);

        return $user;
    },

    // API route to create a new user (POST only)
    '(POST):/api/users' => function(Request $request) {
        $data = $request->getBody();

        // Validate required fields
        if (empty($data['name']) || empty($data['email'])) {
            throw WebException::badRequest("Name and email are required");
        }

        // Check if email already exists
        $exists = DB::getOne(
            "SELECT COUNT(*) FROM users WHERE email = ?",
            [$data['email']]
        );

        if ($exists) {
            throw WebException::badRequest("Email already registered");
        }

        // Insert the new user
        DB::execute(
            "INSERT INTO users (name, email, created_at) VALUES (?, ?, ?)",
            [$data['name'], $data['email'], date('Y-m-d H:i:s')]
        );

        // Return success response with the new user ID
        return [
            'status' => 'success',
            'message' => 'User created successfully',
            'id' => DB::insertId()
        ];
    },

    // API route to update a user (PUT only)
    '(PUT):/api/users/(\d+)' => function(Request $request, $id) {
        $data = $request->getBody();

        // Check if user exists
        $exists = DB::getOne("SELECT COUNT(*) FROM users WHERE id = ?", [$id]);

        if (!$exists) {
            throw WebException::notFound("User {$id} not found");
        }

        // Build update query dynamically based on provided fields
        $fields = [];
        $params = [];

        if (!empty($data['name'])) {
            $fields[] = "name = ?";
            $params[] = $data['name'];
        }

        if (!empty($data['email'])) {
            $fields[] = "email = ?";
            $params[] = $data['email'];
        }

        // Add updated_at timestamp
        $fields[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');

        // Add ID to params
        $params[] = $id;

        // Execute update if there are fields to update
        if (!empty($fields)) {
            DB::execute(
                "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?",
                $params
            );
        }

        // Return the updated user
        return DB::getRow("SELECT id, name, email, created_at, updated_at FROM users WHERE id = ?", [$id]);
    },

    // API route to delete a user (DELETE only)
    '(DELETE):/api/users/(\d+)' => function(Request $request, $id) {
        // Check if user exists
        $exists = DB::getOne("SELECT COUNT(*) FROM users WHERE id = ?", [$id]);

        if (!$exists) {
            throw WebException::notFound("User {$id} not found");
        }

        // Delete the user
        DB::execute("DELETE FROM users WHERE id = ?", [$id]);

        // Return success response
        return [
            'status' => 'success',
            'message' => "User {$id} deleted successfully"
        ];
    },

];
```

#### config/config.php - Main Configuration File

```php
<?php
// config/config.php - Main configuration file
// This file should return an array of configuration values

return [
    // Application settings
    'app' => [
        'name' => env('APP_NAME', 'My SPL Application'),
    ],

    // Twig template settings
    'twig' => [
        'template_path' => SPL_ROOT . '/resources/templates',
        'cache'         => SPL_DEBUG ? false : SPL_ROOT. '/var/cache/views',
        'auto_reload'  => true,
    ],

];
```

#### resources/templates/home.html - Twig Template

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ title }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        header {
            border-bottom: 1px solid #eee;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
        }
        .container {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .info-box {
            background-color: #e9f5ff;
            border-left: 4px solid #0066cc;
            padding: 1rem;
            margin: 1rem 0;
        }
        footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.9rem;
            color: #666;
        }
        .api-section {
            margin-top: 2rem;
        }
        code {
            background-color: #f0f0f0;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <header>
        <h1>{{ title }}</h1>
    </header>

    <div class="container">
        <h2>{{ welcome_message }}</h2>

        <div class="info-box">
            <p>This is a simple web application built with Simon's PHP/Prototyping Library (SPL).</p>
            <p>Current server time: <strong>{{ current_time }}</strong></p>
            <p>Debug mode is <strong>{{ debug_mode }}</strong>.</p>
        </div>

        <div class="api-section">
            <h3>Available API Endpoints:</h3>
            <ul>
                <li><code>GET /api/users</code> - List all users</li>
                <li><code>GET /api/users/{id}</code> - Get a specific user</li>
                <li><code>POST /api/users</code> - Create a new user</li>
                <li><code>PUT /api/users/{id}</code> - Update a user</li>
                <li><code>DELETE /api/users/{id}</code> - Delete a user</li>
            </ul>
        </div>

        <p>
            <a href="/about">About this application</a>
        </p>
    </div>

    <footer>
        Powered by SPL - Simon's PHP/Prototyping Library
    </footer>

    {% if debug_mode == 'enabled' %}
    <script>
        console.log('Debug mode is enabled');
    </script>
    {% endif %}
</body>
</html>
```

### Simple CLI Command

```php
<?php
// cli.php
require_once 'vendor/autoload.php';

use spl\SPL;
use spl\Log;
use spl\DB;

// Initialize the framework
SPL::init(__DIR__, true);

// Parse command line arguments
$options = getopt('', ['action:', 'id:']);
$action = $options['action'] ?? 'list';
$id = $options['id'] ?? null;

// Execute the requested action
try {
    switch ($action) {
        case 'list':
            $users = DB::getAll("SELECT id, name, email FROM users");
            foreach ($users as $user) {
                echo "{$user['id']}: {$user['name']} <{$user['email']}>\n";
            }
            break;

        case 'get':
            if (!$id) {
                throw new InvalidArgumentException("ID is required for 'get' action");
            }
            $user = DB::getRow("SELECT * FROM users WHERE id = ?", [$id]);
            if (empty($user)) {
                echo "User not found\n";
                exit(1);
            }
            echo "ID: {$user['id']}\n";
            echo "Name: {$user['name']}\n";
            echo "Email: {$user['email']}\n";
            break;

        case 'create':
            echo "Enter user details:\n";
            $name = readline("Name: ");
            $email = readline("Email: ");

            DB::execute(
                "INSERT INTO users (name, email) VALUES (?, ?)",
                [$name, $email]
            );

            echo "User created with ID: " . DB::insertId() . "\n";
            break;

        default:
            echo "Unknown action: {$action}\n";
            echo "Available actions: list, get, create\n";
            exit(1);
    }
} catch (Throwable $e) {
    Log::error($e->getMessage());
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}
```
