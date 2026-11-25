# 004 — Error, exception and shutdown handlers; CLI stderr; log path resolution

Status: planning
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
