# 002 — Router fixes: method prefixes, HEAD, named parameters, handler return types

Status: planning
Branch: build-it/002-router-app (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/002-router-app

## Objective

`spl\web\App`'s router mis-parses route method prefixes, gives unprefixed routes zero methods,
has no HEAD support, can't pass named regex captures to handlers, and fatals on unexpected handler
return values. When done, `METHOD:/path`, `METHOD1|METHOD2:/path` and `(METHOD1|METHOD2):/path`
all register correctly, an unprefixed route fails loudly at load time, GET routes answer HEAD
without a body, named captures arrive as named handler arguments, and handler return values map
to responses by type (or fail with a clear 500).

## Context

Phase 2 lane `router-app` (area: `src/web/App.php`); six other agents work on other lanes in
parallel. Request/Response fixes belong to the `request-response` lane, so Response changes here
must be minimal or none. Decisions 1–5 below are the user's.

Codebase facts (master @ fcd7a53):
- `App::loadRoutes()` (App.php:95-114) uses `/^([A-z| ]+):(.*)/i`; `[A-z]` also matches
  `[\]^_\``, and `(POST):/x` doesn't match at all (the `(` isn't in the class), so the whole key
  becomes a path regex and the route 404s. Unprefixed keys register with `array_fill_keys([], h)`
  → no methods → 405 for every method.
- `App::match()` (App.php:123-146) returns `[handler, captures]` with captures from `preg_match`,
  which contains both named and numeric keys for named groups.
- `App::handle()` (App.php:37-88) requires `SPL_ROOT . '/config/routes.php'`, calls `SPL::error()`
  (which `exit`s) for 5xx, and calls `Response::send()` (uses `header()`/`setcookie()`, echoes the
  body). Return conversion only covers string and array; anything else fatals at `->send()`.
- `phpstan-baseline.neon` has one App entry: `call_user_func` given `callable|object`. Baseline
  must not be regenerated; unmatched entries are fine.
- `Request::getMethod()` returns the method as given (no case normalisation).
- CLI SAPI: `header()` is a silent no-op and `headers_list()` is empty, so header output can't be
  asserted end to end; status/headers are asserted on the Response object.

## Baseline

- Check commands: `composer cs-check`; `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`;
  `composer test` (`composer check` runs all three).
- Setup: `composer install` in the worktree. No untracked config needed.
- Setup leftovers: `vendor/`, `.php-cs-fixer.cache`, `.phpunit.cache` (all gitignored).
- Failing before this work: none (cs 0 files, PHPStan `[OK] No errors`, PHPUnit OK 60 tests).
- Not included: `prompts/` (untracked in the user's checkout, unrelated).
