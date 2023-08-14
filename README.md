# Simon's PHP/Prototyping Library

## What

My collection of utility classes for prototyping PHP 8+ apps.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE.md)

## Why

Sometimes I just wanna get shit done in the old school hacky way - quick, dirty and fun.

I don't need massive libraries that cover every possibly scenario or use case, just something simple that's Good Enough.

Plus, I like tinkering with code, so get off my lawn! 🤣

## How

You probably shouldn't, it's almost certainly various degrees broken for all sorts of cases (that I don't care about, yet) 🤣

```
composer require simon-downes/spl
```

## Overview

### General

**The following constants are defined automatically:**
- `SPL_CLI` - true if running in a CLI environment, false otherwise
- `SPL_REQUEST_ID` - a random 8-character hex string
- `SPL_START_TIME` - time that the request started, or (if not available) when the autoloader was called


**Initialise the framework for a particular directory and load a `.env` file if it exists:**

```php
SPL::init(directory: getcwd(), load_env: true );
```

> This will set the `SPL_ROOT` and `SPL_DEBUG` constants.
> `SPL_DEBUG` is set based on:
> - the value in the `APP_DEBUG` environment variable
> - the value of the `APP_ENV` environment variable (false for production, true for others)
> - false if set to anything other than true, false or an empty string

**Output debug representations of variables:**
```php
    // dump and continue
    d($my_var, $my_other_var, ...);

    // dump and die after...
    dd($my_var, $my_other_var, ...);
```

The above commands will only output something if `SPL_DEBUG` is set to `true`.

When calling `dd()` in non-CLI contexts a `text/plain` content-type header will be sent
if no headers have yet been sent.

**Get the value of an environment variable (or a default):**
```php
    env("MY_ENV_VAR_NAME", "Default Value");
```

**Show an error message/page:**

```php
SPL::error($exception);
```

For CLI output, a stack trace is shown.

For web output, a debug page is shown if `SPL_DEBUG` is `true`, otherwise a generic `503` error page is shown. A stack trace is also logged (see Logging below).

### Database

### Logging

**The following levels are supported:**

- `DEBUG`
- `INFO`
- `WARNING`
- `ERROR`
- `CRITICAL`

**Log a message to a file:**
```php
Log::message(message: "Hello World", level: "INFO", file: = '' );
```

Will output the message in this format:

```
Date Time Request-ID [LEVEL] Message
2023-07-13 17:38:10 deee16e2 [INFO] Hello World
```

`file` can be:
- `php` - send output to `error_log()`
- a filename
- an empty string - use the value of `env('APP_LOG_FILE')`, with fallback to `php`

**Convenience methods:**

```php
Log::debug(message: "", file: "");
Log::info(message: "", file: "");
Log::warning(message: "", file: "");
Log::error(message: "", file: "");
Log::critical(message: "", file: "");
```

### HTTP Requests

`TODO`

### Random Data

**Generate some random data:**
```php
Random::hex(length: 40);
Random::string(length: 10 , allowed = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
```

## Strings



`TODO`
