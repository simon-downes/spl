# 003 — Request and Response hardening

Status: planning
Branch: build-it/003-request-response (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/003-request-response

## Objective

Make `spl\web\Request` and `spl\web\Response` behave correctly on real requests: no PHP
warnings when optional server variables are missing, JSON bodies recognised by media type
with malformed JSON reported as 400 instead of 500, the missing common status codes,
meaningful JSON encoding errors, secure cookie defaults, and escaped exception messages in
error responses.

## Context

Phase 2 build in a set of seven parallel PRs; this one owns src/web/Request.php,
src/web/Response.php and src/web/WebException.php. src/web/App.php is owned by the
router-app build and must not be edited.

Verified facts from discovery (master @ fcd7a53):

- `Request::fromGlobals()` reads `$_SERVER['QUERY_STRING']` and `$_SERVER['HTTP_HOST']`
  unguarded: every `php -S` request without a query string logs two "Undefined array key"
  warnings, and a missing Host header is a TypeError. The path is derived with
  `str_replace('?' . QUERY_STRING, '', REQUEST_URI)`.
- The Request constructor JSON-decodes the body only on an exact `application/json`
  content-type, throwing `JsonException` on bad JSON. `SPL::run()` calls
  `Request::fromGlobals()` outside `App::handle()`'s try/catch, so that exception becomes a
  500 error page.
- `Response::setStatus()` throws for 308, 422 and 429; `Response::json()` passes
  `json_encode()`'s `false` into a string parameter (TypeError);
  `Response::fromThrowable()` echoes the exception message into HTML unescaped (reflected
  XSS); `setCookie()`/`send()` use positional `setcookie()` with no HttpOnly/SameSite/
  Secure; `setBody()` stores `content-length` as int in a string-typed array (phpstan
  baseline entry).
- X-Forwarded-For handling in the Request constructor is explicitly out of scope and stays
  as is.

## Baseline

- Check commands: `composer cs-check`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `composer test`
- Setup: `composer install`
- Setup leftovers: none (vendor/ and .php-cs-fixer.cache are gitignored)
- Failing before this work: none (cs clean, phpstan 0 errors, 60 tests pass)
- Not included: `prompts/` (untracked in /workspace, unrelated)
