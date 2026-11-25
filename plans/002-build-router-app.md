# 002 — Router fixes: method prefixes, HEAD, named parameters, handler return types

Status: building
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

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | Route method prefix syntax | Required prefix `METHOD:/path`, `M1\|M2:/path` or `(M1\|M2):/path`, uppercase letters only | `[A-z]` class (matches `[\]^_\``); prefix optional | `(POST):/api/users` silently 404s today; the bracket form is what AGENTS.md documents | user |
| D2 | Route key without a method prefix | Throw at load time; message names the route key | Register with no methods (today: 405 on everything); default to GET | Silent 405s hide config mistakes | user |
| D3 | HEAD requests | A route allowing GET also answers HEAD; status + headers (content-length of the GET body) sent, no body | 405 for HEAD unless listed | HTTP semantics; `GET:/x` ≡ `GET\|HEAD:/x` | user |
| D4 | Named route parameters | If the match has string-keyed captures pass only those, as named args after the Request; otherwise positional as today | Pass all captures; pass an array | Mixed keys error on spread ("positional argument after named argument") | user |
| D5 | Handler return values | string → HTML, array → JSON, Response → as-is, null → 204, JsonSerializable → JSON; else exception → 500 | Fatal on `->send()` (today); cast to string | Clear failures instead of a fatal | user |
| D6 | Prefix grammar strictness | Regex `^(?:\((M)\)\|(M)):(.*)$` with `M = [A-Z]+(?:\|[A-Z]+)*`, case-sensitive; anything else (lowercase, `GET\|:`, unbalanced `(GET:`, spaces) counts as "no prefix" and throws (D2) | Loose `[A-Z\|]+` + `trim('()')`; keep `/i` | Methods are matched against `Request::getMethod()` uppercase as-is, so `get:/x` could never match; empty segments would register a `''` method. One strict grammar gives one clear error | self |
| D7 | Exception types | `InvalidArgumentException` for a bad route key (load); `UnexpectedValueException` for an unsupported return type; message for the latter names method, path and `get_debug_type()` | `WebException(500)`; `LogicException` | Not client errors, so not WebException; both reach `Response::fromThrowable()` → 500 → `SPL::error()` like any other exception | self |
| D8 | Where HEAD falls back to GET | In `match()`: `$handlers[$method] ?? ($method === 'HEAD' ? $handlers['GET'] ?? null : null)` | Auto-register HEAD in `loadRoutes()` | `loadRoutes()` merges with `$current + ...` (first wins), so auto-registering would shadow a later explicit `HEAD:/x` handler; the match-time fallback lets explicit HEAD win | self |
| D9 | Suppressing the HEAD body | In App: `ob_start(); $response->send(); ob_end_clean();` for HEAD | Add `Response::send(bool $body)`; `setBody('')` + restore content-length | Zero change to `Response.php` (another lane); headers and content-length from the GET body are untouched because `header()` isn't buffered | self |
| D10 | 204 response shape for `null` | `new Response(204)` with `content-type` and `content-length` removed via `setHeader(name, null)` | Plain `new Response(204)` | RFC 9110 forbids Content-Length on 204; uses only the existing public API | self |
| D11 | JsonSerializable encoding | `new Response(200, json_encode($result, JSON_THROW_ON_ERROR), 'application/json')` | `Response::json($result->jsonSerialize())` | `jsonSerialize()` may return a scalar; `Response::json()` only accepts `array` | self |
| D12 | Test seam | Extract `protected static dispatch(Request): Response` (match → instantiate → invoke → convert) and `protected static respond(Request, Response): void` (HEAD-aware send) from `handle()`; test via `tests/web/fixtures/TestableApp.php` (subclass redeclaring `$routes`, public wrappers) and fixture route files; two `#[RunInSeparateProcess]` `handle()` tests with `SPL_ROOT` = `tests/web/fixtures/app` | Reflection on `App`; only separate-process `handle()` tests | `handle()` needs `SPL_ROOT` and exits via `SPL::error()` on 5xx; a subclass keeps most tests in-process and isolated (own static `$routes`) | self |
| D13 | Optional named groups that don't participate | PCRE default (trailing unmatched groups absent → handler default; non-trailing → `''`) | `PREG_UNMATCHED_AS_NULL` | Would change today's positional `''` values to `null` and break `string`-typed handlers | self |

## Requirements

- MUST register routes keyed `GET:/x`, `GET|POST:/x` and `(GET|POST):/x` for exactly the listed
  methods and path regex `/x`.
  - AC: after loading each form, `GET /x` (and `POST /x` for the multi forms) dispatches to the
    handler; `PUT /x` throws `WebException` code 405; `(POST):/api/users` + `POST /api/users`
    dispatches (fails before the fix with 404).
  - AC: `[A:/x` (and `_:/x`, `^:/x`) is not registered under method `[`/`_`/`^`; it throws per the
    next requirement (fails before the fix).
- MUST throw `InvalidArgumentException` when loading a route key without a valid method prefix;
  the message contains the offending key.
  - AC: loading `['/x' => fn]` throws with message containing `"/x"`; same for `get:/x`,
    `GET|:/x`, `(GET:/x` (unprefixed fails before the fix: no exception).
- MUST answer HEAD with a GET route's handler when no explicit HEAD handler exists; an explicit
  `HEAD:` handler wins.
  - AC: `GET:/x` and `GET|HEAD:/x` both dispatch `HEAD /x` to the handler with the same status
    and `content-length` as GET; `HEAD` on a `POST:`-only route throws 405; with `GET:/x` → A and
    `HEAD:/x` → B, HEAD dispatches to B.
- MUST send status and headers but no body for HEAD.
  - AC: `respond()` for a HEAD request with a body `hello` outputs `''`; for GET outputs `hello`.
  - AC (separate process): `App::handle()` with fixture `SPL_ROOT` outputs the body for `GET /`
    and `''` for `HEAD /`.
- MUST pass string-keyed captures as named arguments when the route has named groups, and
  positional captures otherwise.
  - AC: `GET:/users/(?<id>\d+)` with `fn(Request $r, string $id)` gets `id = '42'` for
    `/users/42`; `GET:/p/(?<b>\w+)/(?<a>\w+)` with `fn(Request $r, string $a, string $b)` gets
    them by name, not position; `GET:/users/(\d+)` still passes `'42'` positionally (named test
    fails before the fix with "Cannot use positional argument after named argument").
  - AC: a group name with no matching parameter throws `Error` with "Unknown named parameter".
- MUST convert handler results: string → 200 HTML, array → 200 JSON, Response → same instance,
  null → 204 with empty body and no content-type/content-length, JsonSerializable → 200
  `application/json` with `json_encode()` of the object; any other type throws
  `UnexpectedValueException` naming the route and the type, which `Response::fromThrowable()`
  maps to 500.
  - AC: one test per type at `dispatch()`; `int` return throws `UnexpectedValueException` with
    message containing `GET /x` and `int` (fails before the fix with an Error/TypeError), and
    `Response::fromThrowable()` of it `isServerError()`.
- MUST pass `composer check` with no new PHPStan baseline entries or ignores.
  - AC: `composer check` exits 0; `git diff master -- phpstan.neon phpstan-baseline.neon` empty.

Out of scope: Request/Response fixes (JSON body, status codes, `Response::json()` encoding
failures), error handlers and the HEAD body of `SPL::error()`'s 5xx page, method-name case
normalisation in `Request`, docs (Phase 3).

## Design

### Overview

All changes live in `src/web/App.php`. `handle()` becomes: load routes → `dispatch()` → on
Throwable `Response::fromThrowable()` (+ `SPL::error()` for 5xx, unchanged) → `respond()`.
`loadRoutes()` parses prefixes with the D6 regex and throws on a miss; `match()` adds the HEAD
fallback and filters named captures; `dispatch()` owns handler invocation and calls a new
`protected static toResponse(mixed $result, Request $request): Response` for D5.

### Code structure

- `src/web/App.php`: `handle()`, `loadRoutes()`, `match()` changed; new protected static
  `addRoutes(array)` (the parsing half of `loadRoutes()`), `dispatch()`, `respond()`,
  `toResponse()`, and `ROUTE_KEY_REGEX` (D6). Keep `call_user_func($handler, $request, ...$args)`
  so the existing baseline entry still matches (spread of a string-keyed array passes named args).
- New `tests/web/AppTest.php` (`spl\tests\web\AppTest`, final, `test*` methods, follows
  `tests/StrTest.php` for data providers).
- New `tests/web/fixtures/TestableApp.php` (`spl\tests\web\fixtures\TestableApp extends App`):
  redeclares `protected static array $routes = []`; public `reset()`, `add(array)`,
  `load(string $file)`, `routes()`, `matchRoute()`, `dispatchRequest(Request)`,
  `respondTo(Request, Response)`.
- New fixture route files under `tests/web/fixtures/routes/*.php` (each returns an array), and
  `tests/web/fixtures/app/config/routes.php` for the separate-process `handle()` tests.
  PHPUnit only collects `*Test.php`, so fixtures aren't run as tests.

### Error handling

- Bad route key → `InvalidArgumentException` from `loadRoutes()` → caught in `handle()` → 500 via
  `SPL::error()` (logged, error page).
- Unsupported return → `UnexpectedValueException` from `toResponse()` → same path.
- Unknown named parameter → PHP `Error` from the call → same path.

## Milestones

### [x] M1 — parse method prefixes strictly and reject unprefixed routes

- **Approach:** First add `TestableApp` with `reset()`, `load()`, `routes()` and
  `matchRoute(path, method)` (wraps the existing protected `match()`, so the tests run against
  today's code) and `AppTest` cases for the three prefix forms, `(POST):/api/users`, `[A:/x`, and
  an unprefixed key; see them fail. Then implement D6/D7 in `loadRoutes()` and update its
  docblock to the three forms. Prefactor in the same milestone: extract `dispatch()`/`respond()`
  from `handle()` (behaviour-preserving), add `dispatchRequest()`/`respondTo()` to `TestableApp`,
  and cover it with one `#[RunInSeparateProcess]` `App::handle()` GET test.
- **Test seam:** `TestableApp::load()` + `matchRoute()` with fixture route files;
  `App::handle()` in a separate process (defines `SPL_ROOT` = `tests/web/fixtures/app`).
- **Edge cases:** `GET:/a:b` → path `/a:b`; same path twice with different methods → merged.
- **Deliverable:** all three prefix forms route; bad keys throw with the key in the message.
- **Verify:** `vendor/bin/phpunit tests/web/AppTest.php` passes; `composer check` exit 0.
- **Outcome:** Before the fix `AppTest` had 13 failures/errors: `(POST):/api/users` →
  `WebException: Not Found`; `(GET|POST):/x` registered under a bracketed path; all 9 invalid
  keys (incl. `[A:/x`, `_:/x`, `^:/x`, `(GET:/x`) loaded without an exception; the int return gave
  `TypeError` from `dispatch()`; the separate-process `handle()` test got the 404 body. After:
  `vendor/bin/phpunit tests/web/AppTest.php` OK (17 tests, 35 assertions); `composer check` exit 0
  (cs 0/28 files, PHPStan `[OK] No errors`, PHPUnit OK 77 tests, 163 assertions). Deviations:
  `addRoutes()` seam; unsupported-type exception moved here from M2.

### [x] M2 — convert handler return values by type

- **Approach:** Tests first for null and JsonSerializable returns, and Response/string/array
  pass-through. Extend `toResponse()` (from M1) with D10 and D11; update the `handle()` docblock
  list and the exception message's expected-types list.
- **Test seam:** `TestableApp::dispatchRequest()`; `Response::fromThrowable()` for the 500 mapping.
- **Deliverable:** every listed type maps as specified; others throw a clear exception.
- **Verify:** `vendor/bin/phpunit tests/web/AppTest.php` passes; `composer check` exit 0.
- **Outcome:** Before: null and both JsonSerializable cases (array and scalar `jsonSerialize()`)
  threw `UnexpectedValueException` (3 errors). After: `toResponse()` maps null → 204 with no
  content-type/content-length and JsonSerializable → 200 JSON; string/array/Response
  pass-through tests added. `AppTest` OK (23 tests, 52 assertions); `composer check` exit 0
  (cs 0/28, PHPStan `[OK] No errors`, PHPUnit OK 83 tests, 180 assertions).

### [x] M3 — pass named route captures as named arguments

- **Approach:** Tests first (named, reordered named, positional, unknown name). In `match()`,
  after `preg_match`, keep only string keys when any exist, else drop `[0]` as today (D4, D13).
- **Test seam:** `TestableApp::dispatchRequest()`.
- **Deliverable:** named-group handlers receive named args; unnamed routes unchanged.
- **Verify:** `vendor/bin/phpunit tests/web/AppTest.php` passes; `composer check` exit 0.
- **Outcome:** Before: the three named-group tests errored with "Cannot use positional argument
  after named argument during unpacking" and the unknown-name test got that message instead of
  "Unknown named parameter $user_id". After: `match()` keeps only string keys when any exist;
  `dispatch()` calls `call_user_func($handler, $request, ...$args)` (baseline entry still
  matches). `AppTest` OK (28 tests, 58 assertions); `composer check` exit 0 (PHPUnit OK 88
  tests, 186 assertions).

### [ ] M4 — answer HEAD on GET routes without a body

- **Approach:** Tests first (HEAD on GET / GET|HEAD / POST-only routes, explicit HEAD wins,
  `respondTo()` output for HEAD vs GET, separate-process `handle()` HEAD test). Add the D8 fallback
  in `match()` and the D9 output buffering in `respond()`.
- **Test seam:** `TestableApp::dispatchRequest()`/`respondTo()` with `expectOutputString()`;
  `#[RunInSeparateProcess]` `App::handle()`.
- **Deliverable:** HEAD returns GET's status/headers with no body.
- **Verify:** `vendor/bin/phpunit tests/web/AppTest.php` passes; `composer check` exit 0.
- **Outcome:**

## Deviations

- M1: `loadRoutes()` now only `require`s the file and passes the array to a new protected
  `addRoutes(array)`, so tests register closures directly instead of one fixture file per case
  (only the separate-process `handle()` test uses a routes file). No behaviour change.
- M1: the `dispatch()` prefactor needs a `Response` return type for PHPStan, so `toResponse()`
  with the unsupported-type `UnexpectedValueException` (string/array/Response accepted) landed in
  M1; M2 adds null and JsonSerializable. Scope unchanged.

## Risks

- Breaking: unprefixed and lowercase-prefixed route keys now throw at load (previously they
  registered but answered 405/never matched). Any consumer relying on the old silent behaviour
  gets a 500 until the key is fixed; the message names the key. AGENTS.md/README examples use
  unprefixed keys (`'/' => ...`, `'/api/users' => ...`); the Phase 3 docs PR must prefix them.
- `Response::send()` is owned by the `request-response` lane; if that PR changes `send()` to flush
  or to send the body outside PHP output (unlikely), D9's buffering would stop suppressing it.
- In CLI, `header()` is a no-op, so the HEAD test can't observe sent headers; status and
  content-length are asserted on the Response from `dispatch()` instead.

## Review

- Plan review: 1 pass, self-conducted against PLAN-REVIEW.md (no subagent tool in this
  session). No ❌. Fixed: ⚠️ M1 tests needed `dispatch()` before it existed, so the bug could
  not be seen failing first → M1 tests use `matchRoute()` over the existing `match()`, and the
  `dispatch()`/`respond()` extraction is a labelled prefactor step; ⚠️ AGENTS.md examples use
  unprefixed route keys that will now throw → added to Risks for the docs PR. Verified claims:
  App.php line ranges, regex, `$current + ...` merge, baseline entry text (call_user_func,
  count 1), `Request::getMethod()` has no case normalisation, PHPUnit collects only `*Test.php`
  (default suffix, `tests` directory in phpunit.xml.dist), CLI `header()`/`headers_list()`
  behaviour (probed with `php -r`).

## Outcome

PR: pending
CI: pending

## Follow-ups
