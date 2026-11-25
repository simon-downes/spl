# 004 — Error, exception and shutdown handlers; CLI stderr; log path resolution

Status: done
Branch: build-it/004-error-handling (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/004-error-handling

## Objective

SPL only reports errors that reach `SPL::run()`'s catch: scripts that call `SPL::init()` directly
get PHP's default uncaught-exception output, fatals (memory, time limit) are never logged with the
request ID, PHP warnings are silent in debug mode, CLI failures print nothing when a log file is
set, a relative `APP_LOG_FILE` resolves against the process cwd, and the debug error page echoes
exception data unescaped. When done, `SPL::init()` installs error/exception/shutdown handlers,
CLI errors reach STDERR exactly once, relative log paths resolve against `SPL_ROOT`, and the
error page escapes everything it prints.

## Context

Phase 2 build (one of seven parallel PRs); this one owns `src/SPL.php` (init/run/error),
`src/Log.php` and `src/error.php`. Scope was decided by the user (Decisions, `user` rows).

Codebase facts:
- `SPL::init()` (src/SPL.php:42-75) defines `SPL_ROOT`/`SPL_DEBUG`, loads `.env`, computes
  `$log_file` (relative -> `SPL_ROOT/...`) and then discards it. It would also turn the special
  value `php` into `SPL_ROOT/php`.
- `Log::message()` (src/Log.php:68-95) reads `env('APP_LOG_FILE')` directly; `''`/`php` ->
  `error_log()`, otherwise `file_put_contents(FILE_APPEND|LOCK_EX)`. Prefix includes
  `SPL_REQUEST_ID`. No directory creation.
- `SPL::error()` (src/SPL.php:164-181) formats with `util\Debug::toString()`, logs via
  `Log::error()`, requires `src/error.php` when `!SPL_CLI`, then `exit($exit)` unless 0.
- `web\App::handle()` calls `SPL::error($e)` for 5xx (src/web/App.php:81); not changed here.
- error.php echoes `$err['name']`, message, code, file, line and trace file/line/class/type/
  function without escaping (lines 50, 142-143, 163-165, 180-184).
- PHP 8.4 CLI here: `error_log` ini unset (error_log() writes to stderr), `display_errors` off,
  `log_errors` on. Prototype confirmed: an error handled by a user handler does not show in
  `error_get_last()`; a memory-exhausted fatal is visible in a shutdown function, which can still
  allocate.

## Baseline

- Check commands: `composer cs-check`; `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`;
  `composer test` (`composer check` runs all three).
- Setup: `composer install` in the worktree. No untracked config needed.
- Setup leftovers: `vendor/`, `.php-cs-fixer.cache`, `.phpunit.cache` (gitignored).
- Failing before this work: none (cs 0 files, PHPStan 0 errors, 60 tests pass).
- Not included: `prompts/` (untracked in the user's checkout, unrelated).

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | PHP warnings/notices/deprecations | `set_error_handler()` in `init()` only when `SPL_DEBUG`; converts to `ErrorException` only if `error_reporting() & $severity` (honours `@`); otherwise returns `false` | always convert; leave as-is | Debug should fail loudly; production keeps PHP's default handling | user |
| D2 | Uncaught Throwables after `init()` | `set_exception_handler()` routing to `SPL::error()` | only `SPL::run()`'s catch | Scripts that call `init()` directly get the same logging/output | user |
| D3 | Fatal errors | `register_shutdown_function()` checking `error_get_last()` for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR; logs via `Log` and shows page/STDERR; no double handling or recursion | none | Memory/time-limit fatals are otherwise never logged with the request ID | user |
| D4 | CLI error output | `SPL::error()` also writes the `util\Debug` trace to STDERR in the CLI, except when the log destination already is the terminal | log only | With `APP_LOG_FILE` set a failing command prints nothing today | user |
| D5 | Relative log paths | `init()`'s resolution (relative -> `SPL_ROOT`) takes effect; `php` keeps meaning `error_log()`; missing directory is created; `Log` works without `init()` | status quo | `logs/app.log` resolves against `public/` under `php -S` and the write fails (verified by user) | user |
| D6 | error.php escaping | `htmlspecialchars(..., ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` on class, message, code, file, line, trace file/line/class/type/function | none | Exception messages often contain user input (XSS on the debug page) | user |
| D7 | How `init()` passes the resolved path to `Log` | new `Log::setFile(?string $file)` static setter; `Log::message()` uses explicit `$file`, else the set value, else `env('APP_LOG_FILE')`; `null` resets to the env fallback | write back into `$_ENV['APP_LOG_FILE']`; `Log` resolving relative paths against `SPL_ROOT` itself | Keeps env as the no-init fallback (D5) without mutating config; `Log` stays independent of `SPL_ROOT`, which tests must never define | self |
| D8 | Where the log directory is created | in `Log::message()` before each file write: `is_dir($dir) \|\| @mkdir($dir, 0777, true)`; if the directory still doesn't exist, an `E_USER_WARNING` with the `error_get_last()` reason (e.g. `mkdir(): Permission denied`) and the message is skipped; otherwise `file_put_contents()` reports any write failure as today (post-PR review) | only in `init()` | Covers explicit `$file` paths and a directory removed at runtime; `@` avoids a race warning when two requests create it at once; umask applies to 0777 | self |
| D9 | Handler entry points | public static `SPL::handleError()`, `SPL::handleException()`, `SPL::handleShutdown()` (docblock `@internal`), registered as `[static::class, '...']` | closures inside `init()` | Directly callable from tests without `init()` (brief testing notes); visible in stack traces | self |
| D10 | Detecting "log destination is the terminal" | new `Log::usesErrorLog(string $level): bool` (`@internal`) (level passes `APP_LOG_LEVEL` and destination is `''`/`php`); `SPL::error()` skips the STDERR write when `SPL_CLI && Log::usesErrorLog('ERROR') && ini_get('error_log') === ''` | always print and skip logging; check only `APP_LOG_FILE` | Keeps the prefixed log line with request ID; with `APP_LOG_LEVEL=CRITICAL` nothing is logged, so the trace must still be printed | self |
| D11 | Failures inside `SPL::error()` | re-entrancy flag `protected static bool $handling`; a Throwable from `Log::error()` -> `error_log()` the trace plus the logging failure (counts as terminal output for D10); any other Throwable while formatting/rendering -> `error_log()` a one-line summary of both; still exits as requested. A re-entrant call or a fatal seen by the shutdown handler while `$handling` is true -> one-line `error_log()` only | let it propagate | A throw from the exception handler becomes a PHP fatal and loses the original error | self |
| D12 | Shutdown exit status | `handleShutdown()` calls `SPL::error($e, 0)`; PHP keeps its fatal status 255 | `exit(1)` in the shutdown function | `exit` would skip shutdown functions registered after ours (apps, libraries) | self |
| D13 | Memory headroom for memory-exhausted fatals | no reserved buffer | reserve 32 KB in `init()`, released in `handleShutdown()` (Symfony's ErrorHandler approach) | M4 evidence: the out-of-memory fixture (many small allocations) is formatted, logged and printed without a reserve, so the buffer would be dead code (see Deviations) | self |
| D14 | Test seams | end-to-end tests run fixture scripts with `proc_open(PHP_BINARY)` in a temp app root (`tests/SPLTest.php`); `Log` and `handleError()` unit-tested in-process (no `init()`); error.php with `#[RunInSeparateProcess]` (`tests/ErrorPageTest.php`) | `#[RunInSeparateProcess]` for everything | Exit codes, STDERR and real fatals can only be observed from outside the process; `exit()` kills a PHPUnit child | self |
| D15 | Handler registration order | at the end of `init()`, after `SPL_DEBUG` is defined and the log file set | first thing in `init()` | `SPL::error()`/error.php need `SPL_DEBUG`; an `.env` parse error before that stays with `SPL::run()`'s catch as today | self |

## Requirements

- MUST, when `SPL_DEBUG` is true, convert reported warnings/notices/deprecations to `ErrorException`, and leave `@`-suppressed or unreported ones alone.
  - AC: `SPLTest`: debug fixture doing `echo $undefined;` exits 1 and STDERR contains `ErrorException` and `Undefined variable`; the same with `@$undefined` prints its marker and exits 0.
  - AC: `SPLTest`: `SPL::handleError(E_WARNING, 'msg', 'f.php', 7)` throws `ErrorException` with severity `E_WARNING`, file `f.php`, line 7; with `error_reporting(E_ALL & ~E_WARNING)` it returns `false`.
- MUST NOT install the error handler when `SPL_DEBUG` is false.
  - AC: fixture with `APP_DEBUG=false` doing `echo $undefined;` prints its marker and exits 0.
- MUST route uncaught Throwables after `SPL::init()` (without `SPL::run()`) to `SPL::error()`.
  - AC: fixture `init()` + `throw new RuntimeException('boom')` exits 1; the log file has `[ERROR]`, the fixture's `SPL_REQUEST_ID` and `boom`.
- MUST log fatal errors at shutdown with the request ID and show the trace on STDERR (CLI) / the error page (web).
  - AC: fixture exhausting `memory_limit` via many small allocations: log file has the request ID and `Allowed memory size` exactly once; STDERR contains `ErrorException` and `Allowed memory size`; exit code 255.
  - AC (manual): `php -S` fixture route that exhausts memory returns HTML containing `Allowed memory size` and the log file under the app root gets the entry.
- MUST handle each error once and never recurse if `SPL::error()` fails.
  - AC: in the uncaught-exception and fatal fixtures the log file contains the error message exactly once.
  - AC: debug fixture whose `APP_LOG_FILE` is an existing directory (unwritable) exits 1 and STDERR contains the original message and the logging failure, and no `PHP Fatal error`.
  - AC: fixture whose `APP_LOG_FILE` is under a read-only directory reports `Unable to create log directory ...: mkdir(): Permission denied` (debug: via the fallback; non-debug: PHP warning), never `No such file or directory`.
- MUST, in the CLI, write the formatted trace to STDERR when the log destination is not the terminal, and not print it twice when it is.
  - AC: fixture with `APP_LOG_FILE=logs/app.log`: STDERR contains `RuntimeException {` and `boom`.
  - AC: fixtures with no `APP_LOG_FILE` and with `APP_LOG_FILE=php`: STDERR contains `boom` exactly once.
  - AC: fixture with `APP_LOG_LEVEL=critical`: STDERR still contains `boom`.
- MUST resolve a relative `APP_LOG_FILE` against `SPL_ROOT` (not cwd), create the missing directory, and keep `php` meaning `error_log()`.
  - AC: fixture run with cwd = another temp dir and `APP_LOG_FILE=logs/app.log` (no `logs/`): `<root>/logs/app.log` gets the entry; cwd stays empty. Fails before the fix.
  - AC: `APP_LOG_FILE=php` fixture creates no `<root>/php`.
  - AC: Windows absolute paths (`C:\logs\app.log`, `c:/logs/app.log`, `\\server\share\app.log`) are not prefixed with `SPL_ROOT`.
- MUST keep `Log` usable without `SPL::init()`.
  - AC: `LogTest`: with `$_ENV['APP_LOG_FILE']` = temp file, `Log::info()` appends a prefixed line; with no destination and `ini_set('error_log', <tmp>)`, the line lands in `<tmp>`; an explicit `$file` beats `setFile()`; `setFile(<dir>/a/b/x.log)` creates `a/b`; `usesErrorLog()` reflects level and destination.
- MUST HTML-escape every exception value the error page prints.
  - AC: `ErrorPageTest` (separate process, `SPL_DEBUG` true): `ErrorException('<script>alert("x")</script>', 0, E_WARNING, '/tmp/<b>.php', 3)` renders `&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;` and `/tmp/&lt;b&gt;.php`, never `<script>` or `<b>`; with `SPL_DEBUG` false the message is absent.

Out of scope: Twig config key in `SPL::render()`, `Response::fromThrowable()` escaping, router/`App`, CLI command handling, changing PHP ini settings (`display_errors`, `log_errors`), README/AGENTS/CONTRIBUTING.

## Design

### Overview

`SPL::init()` finishes by passing the resolved log path to `Log::setFile()` and registering three static handlers: `handleError` (debug only), `handleException` and `handleShutdown`. All three funnel into `SPL::error()`, which formats once with `util\Debug`, logs, then shows STDERR (CLI, unless already on the terminal) or error.php (web), guarded by a re-entrancy flag with an `error_log()` fallback.

### Code structure

- `src/Log.php`: `protected static ?string $file = null`; `setFile(?string)`; `usesErrorLog(string $level): bool` and `isErrorLog(string $file): bool` (both public `@internal`, for `SPL`); a protected destination lookup (explicit > set > env); directory creation before the write.
- `src/SPL.php`: `init()` tail; `$handling` static and `FATAL_ERRORS` constant; `handleError()`, `handleException()`, `handleShutdown()`; `error()` rewritten per D10/D11. `render()` untouched (bootstrap-env-str lane).
- `src/error.php`: an `$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ...)` helper used for every echoed value.
- New tests (all new files): `tests/LogTest.php`, `tests/SPLTest.php` (fixture runner helper inside the class), `tests/ErrorPageTest.php` (src/error.php has no class, so the page gets a descriptive name).

### Data flow

- Uncaught exception (CLI): PHP -> `handleException($e)` -> `error($e, 1)` -> `Debug::toString` -> `Log::error` (file) -> STDERR trace -> `exit(1)`. Shutdown sees no fatal.
- Warning in debug: PHP -> `handleError` throws `ErrorException` -> caller or `handleException`.
- Fatal: PHP fatal -> shutdown -> `error_get_last()` type in mask -> `$handling` ? one-line `error_log()` : `error(new ErrorException(msg, 0, type, file, line), 0)`.
- Log write fails in debug: `file_put_contents` warning -> `ErrorException` out of `Log::error` -> caught in `error()` -> `error_log($trace)` + `error_log('Unable to write log: ...')` -> no STDERR duplicate when the `error_log` ini is unset.

### Config

- `APP_LOG_FILE`: relative now resolves against `SPL_ROOT` after `init()` (absolute = starts with `/` or `\`, or a drive like `C:\`/`C:/`); `php`/empty = `error_log()` (one predicate, `Log::isErrorLog()`, used by both `Log` and `init()`); directory auto-created.

## Milestones

### [x] M1 — Relative log paths resolve against SPL_ROOT

- **Approach:** Bug fix: first write `tests/SPLTest.php` with a fixture runner (writes `<root>/.env` and `<root>/run.php` requiring `dirname(__DIR__) . '/vendor/autoload.php'`, runs `proc_open([PHP_BINARY, run.php])` with a separate cwd, returns exit code/stdout/stderr; temp dirs removed in `tearDown()`), and the relative-path test; see it fail. Then add `Log::setFile()`, the destination lookup and directory creation (D7, D8), `Log::usesErrorLog()`, and the `init()` tail calling `Log::setFile()` (no resolution for `''`/`php`). Add `tests/LogTest.php` following `tests/util/EnvTest.php` (temp files, `$_ENV` cleanup, reset `Log::setFile(null)` and the `error_log` ini in `tearDown()`).
- **Test seam:** fixture process (`SPLTest`); `Log` public static API (`LogTest`).
- **Edge cases:** `APP_LOG_FILE=php` -> `error_log()`, no file; absolute path unchanged; unset -> `error_log()`.
- **Deliverable:** after `init()` a relative `APP_LOG_FILE` is written under `SPL_ROOT`, its directory created.
- **Verify:** `vendor/bin/phpunit tests/LogTest.php tests/SPLTest.php` passes (relative-path test failed before the fix); `composer check` passes.
- **Outcome:** `Log::setFile()`/`usesErrorLog()`/`destination()`/`isErrorLog()` and directory creation in src/Log.php; `init()` now calls `Log::setFile()` and no longer turns `php` into `SPL_ROOT/php`. Before the fix `testRelativeLogFileResolvesAgainstRootAndCreatesDirectory` and `testAbsoluteLogFileIsUsedAsIs` failed (file missing); after, `phpunit tests/LogTest.php tests/SPLTest.php` OK (11 tests) and `composer check` OK (71 tests).

### [x] M2 — CLI errors reach STDERR once, with a safe fallback

- **Approach:** Rewrite `SPL::error()` per D10/D11: `$handling` guard, `Log::error()` in try/catch with `error_log()` fallback, `fwrite(STDERR, $trace . "\n")` in the CLI unless already on the terminal, outer catch -> one-line `error_log()`, `finally` resets `$handling`, then `exit`. These fixtures call `SPL::error()` explicitly from a catch block (`SPLTest::CAUGHT_ERROR`); M3 adds separate uncaught-exception fixtures.
- **Test seam:** fixture process STDERR/exit code.
- **Edge cases:** no `APP_LOG_FILE` / `php` -> printed once; `APP_LOG_LEVEL=critical` -> printed; unwritable log in debug -> fallback, exit 1.
- **Deliverable:** a failing CLI script shows the trace on the terminal exactly once.
- **Verify:** `vendor/bin/phpunit tests/SPLTest.php` passes; `composer check` passes.
- **Outcome:** `SPL::error()` split into the guarded `error()`, `report()` and `summary()` (src/SPL.php). Before the change 6 of the new SPLTest cases failed (no STDERR output with a log file, with `APP_LOG_LEVEL=critical`, with an `error_log` ini file, and on log-write failure); after, `composer check` OK (79 tests). The generic "handling failed" catch is not exercised by a test (no realistic trigger in the CLI).

### [x] M3 — Exception and error handlers

- **Approach:** Add `handleException()` (calls `static::error($e)`) and `handleError()` (D1), registered at the end of `init()` (D15); error handler only when `SPL_DEBUG`. New fixtures throw without catching.
- **Test seam:** fixture process; `SPL::handleError()` called directly in-process.
- **Edge cases:** `@` suppression; `APP_DEBUG=false` leaves warnings alone.
- **Deliverable:** `init()`-only scripts log uncaught exceptions and, in debug, fail on warnings.
- **Verify:** `vendor/bin/phpunit tests/SPLTest.php` passes; `composer check` passes.
- **Outcome:** `SPL::handleError()`/`handleException()` registered at the end of `init()`. New SPLTest cases: uncaught exception (exit 1, logged once with request ID, no PHP fatal), debug warning -> `ErrorException` (exit 1), `@` suppressed (exit 0, no stderr), non-debug warning left to PHP, direct `handleError()` throw/ignore; the log-write-failure test now asserts the debug fallback (`Unable to write log:`). `composer check` OK (85 tests). Note: the host php.ini sets `error_reporting` to `E_ALL & ~E_DEPRECATED`, so the in-process `handleError()` tests set `error_reporting` explicitly.

### [x] M4 — Fatal errors handled at shutdown

- **Approach:** Add `handleShutdown()` (D3, D11-D13); register in `init()`. Fixtures: `ini_set('memory_limit', '16M')` then grow a linked list of small objects forever (exhausts on a small allocation); `set_time_limit(1)` and loop.
- **Test seam:** fixture process (log file, STDERR, exit code 255).
- **Edge cases:** non-fatal last error (warning) -> ignored; fatal while `$handling` -> one-line `error_log()`.
- **Deliverable:** memory-exhausted scripts are logged once with the request ID and shown on STDERR.
- **Verify:** `vendor/bin/phpunit tests/SPLTest.php` passes; manual `php -S` fixture: a route exhausting memory returns the error page and logs under the app root.
- **Outcome:** `SPL::handleShutdown()` and `FATAL_ERRORS` in src/SPL.php, registered in `init()`. `testFatalErrorIsHandledAtShutdown` (out of memory, out of memory in debug, time limit): exit 255, `ErrorException`/`"ERROR"` severity and the PHP message on STDERR, logged exactly once with the request ID. The planned 32 KB reserve was tried and removed: with it set to an empty string the out-of-memory cases still passed. `composer check` OK (88 tests). Manual `php -S` check (app in /tmp/spl-web, cwd `public/`, `APP_DEBUG=true`, `APP_LOG_FILE=logs/app.log`): `GET /oom` returned 500 with the `ErrorException` page showing `Allowed memory size of 16777216 bytes exhausted`; `/tmp/spl-web/logs/app.log` got exactly one entry and nothing was created under `public/`.

### [x] M5 — Escape the error page

- **Approach:** Bug fix: write `tests/ErrorPageTest.php` (`#[RunInSeparateProcess]`, define `SPL_DEBUG`, set `$error`, `ob_start()`, `require` src/error.php) and see it fail; then add the `$h` helper in src/error.php and use it for every echoed value.
- **Test seam:** rendered HTML of src/error.php.
- **Deliverable:** the debug error page shows exception data as text.
- **Verify:** `vendor/bin/phpunit tests/ErrorPageTest.php` passes (failed before); `composer check` passes.
- **Outcome:** `$h` helper in src/error.php, used for the title, name, message, code, file, line and trace file/line/class/type/function. `ErrorPageTest` (3 separate-process tests: message/file escaping, trace `eval()'d` quote and `->`, production page hides data) failed 2/3 before and passes after; `composer check` OK (92 tests). Browser before/after screenshots of `GET /xss?q=<b>bold</b> <marquee>injected</marquee>` show rendered markup before and literal text after.

## Deviations

- M4: D13 reversed: no 32 KB memory reserve. The out-of-memory fixture passes without it, so it would be untestable dead code. No scope change.

- M4 (manual web check): with the new debug error handler every `php -S` request without a query string returned 500, because `Request::fromGlobals()` read the unset `$_SERVER['QUERY_STRING']` (src/web/Request.php:26, request-response lane). Added the smallest fix (`?? ''`) and `SPLTest::testDebugWebRequestWithoutQueryStringIsNotAnError` (failed before). Scope: one-line touch in another lane, listed in the PR under "Touches other areas".

## Risks

- With the `error_log` ini set to `/dev/stderr` (or similar) in the CLI, the trace is printed twice: only an empty `error_log` setting is treated as the terminal (D10).
- The "fatal during `SPL::error()`" path (shutdown sees `$handling` true -> one-line `error_log()`) has no automated test: no deterministic trigger exists without user code in the formatter.
- `src/web/Request.php` is the request-response PR's file; the one-line `QUERY_STRING` fix may conflict with it.

- The debug error handler turns any existing warning/notice/deprecation in app code, other SPL code or libraries into a 500/exit 1. Only the `QUERY_STRING` one was found by the manual web check; others may surface (by design, D1). `SPL_DEBUG` is true whenever `APP_DEBUG` isn't set and `APP_ENV` isn't prod-like (including unset), so this applies by default, e.g. to an `init()`-only cron script with no `.env` (user confirmed this default after the post-PR review).

- PHP's own fatal output (`display_errors`/`log_errors`) still appears alongside SPL's handling; ini settings are out of scope.
- In non-debug mode a failing log write (or log directory creation) is only a PHP warning, so the error may be missing from the log file (it still reaches STDERR in the CLI; on the web only PHP's warning records it). Kept by user decision; the `SPL::error()` docblock says so.

## Review

- Code review: self-conducted (no subagent tool in this session), roles `general` and `risk` (risk applies: public library API, config key semantics, per-request code, external input on the error page), 1 round against references/CODE-REVIEW.md on fcd7a53...HEAD. Fixed: ℹ️ stale `SPLTest` class docblock (claimed every test runs a fixture). Accepted as Risks, not fixed: ℹ️ duplicate CLI output when `error_log` ini points at stderr explicitly (needs guessing what a path means); untested fatal-during-handling guard. Rejected: ⚠️ "`is_dir()` per log write is a per-request cost" (PHP's stat cache makes it one stat per request per directory; no evidence of cost); ⚠️ "debug handler breaks libraries that emit deprecations" (Twig/Symfony use `@trigger_error`, which `error_reporting()` honours; converting unsuppressed ones is the user's decision D1). Spec check: every MUST traced to a passing test or the manual web check (M4 Outcome); the `Request.php` change traces to the M4 deviation.
- Merge risk: Medium: debug mode now turns any latent warning in app or SPL code into a 500/exit 1 (one was found and fixed in `Request::fromGlobals()`), limited to `SPL_DEBUG` environments, which includes any environment with no `APP_DEBUG` and no prod-like `APP_ENV`; production only gains the exception/shutdown handlers and the log path resolution, both covered by fixture tests.
- Plan review: 1 pass, self-conducted (no subagent tool in this session) against references/PLAN-REVIEW.md. Findings: ℹ️ new test files not marked new (fixed in Code structure); ℹ️ `tests/ErrorPageTest.php` breaks the mirror-by-class naming (explained in Code structure). Codebase claims re-checked: SPL.php:42-75/164-181, Log.php:68-95, App.php:81, error.php echo sites, check commands. No ❌, so no second pass.

### Independent review (post-PR)

Review: general + risk roles on fcd7a53...5c5e42b (no ❌/⚠️; six ℹ️, two ❓). All findings fixed; none rejected.

- ℹ️ `SPL::error()` docblock overstated the `error_log()` fallback: fixed by qualifying it (the fallback only happens when the log write throws, i.e. in debug mode; outside debug mode only PHP's warning reports the failure). `Log::message()` still doesn't throw on a failed write (user decision).
- ℹ️ "empty or `php` means `error_log()`" written twice: `Log::isErrorLog()` is now public `@internal` and `SPL::init()` uses it.
- ℹ️ `runFixture()` could deadlock on >64 KB of stderr and had no timeout: now reads both pipes non-blocking with `stream_select()` and kills the fixture at a deadline (default 30 s), failing the test. Tests: `testFixtureOutputLargerThanAPipeBufferIsRead` (200 KB to stderr before stdout) and `testFixtureThatNeverFinishesFailsTheTest` (1 s deadline); both hung before the change (killed by `timeout`), pass after.
- ℹ️ M4 heading checkbox unticked: ticked.
- ℹ️ `@mkdir` hid the real cause of a log directory failure: after a failed `mkdir` that left no directory, `Log::message()` raises `E_USER_WARNING` "Unable to create log directory <dir>: <error_get_last()>" and skips the write (D8). Test: `testUncreatableLogDirectoryReportsTheRealCause` (read-only parent, debug and non-debug), failed before ("No such file or directory"). `testLogWriteFailureStillReportsTheError` now makes the log path an existing directory, so it still exercises the `file_put_contents()` failure rather than the new mkdir path.
- ℹ️ Windows absolute `APP_LOG_FILE` became `SPL_ROOT/C:\...`: `SPL::isAbsolutePath()` treats a leading `/` or `\` (covers UNC) and `X:\`/`X:/` as absolute. Test: `testWindowsAbsoluteLogFileIsNotResolvedAgainstRoot` (3 cases), failed before.
- ❓ `Log::usesErrorLog()` public API or internal: user decision, marked `@internal` (only `SPL::report()` uses it).
- ❓ strict debug handler on by default: user decision, keep it; the Risks and the PR's Risk/Breaking changes now say it applies whenever `APP_DEBUG` is unset and `APP_ENV` isn't prod-like, e.g. `init()`-only cron scripts with no `.env`.
- Cross-PR: the `src/web/Request.php` `QUERY_STRING` conflict with #3 is resolved at merge time by taking #3's version; this PR's `?? ''` change is left as is.
- Checks after the fixes: cs 0 files, PHPStan 0 errors, 99 tests / 357 assertions; `tests/SPLTest.php` passed 3 of 3 reruns.

## Outcome

`SPL::init()` now resolves `APP_LOG_FILE` against `SPL_ROOT` and hands it to `Log::setFile()`, and registers exception, shutdown and (debug-only) error handlers; `SPL::error()` writes the trace to STDERR in the CLI unless the log already went there, and falls back to `error_log()` if logging fails; the error page escapes all exception data. Evidence: `composer check` OK (cs 0 files, PHPStan 0 errors, 92 tests / 313 assertions; 99 / 357 after the post-PR review fixes); manual `php -S` checks and browser before/after screenshots (M4, M5 Outcomes). Not verified: the fatal-during-`SPL::error()` guard; behaviour under php-fpm/Apache (only `php -S` and CLI were run).

PR: https://github.com/simon-downes/spl/pull/7
CI: none (the repository has no CI checks)

## Follow-ups

- Router: route keys like `(GET):/path` (the AGENTS.md format) never match because `App::loadRoutes()`'s `[A-z| ]+` excludes `(`; and routes without a method prefix return 405 (router-app lane). Seen during the manual web check.
- Docs (Phase 3): see the PR's "For the docs PR" list in the build report.
