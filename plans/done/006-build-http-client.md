# 006 — Harden the HTTP client

Status: done
Branch: build-it/006-http-client (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/006-http-client

## Objective

`spl\Http` can hang forever (no timeouts), mis-parses responses that carry more than one
header block (`100 Continue`, followed redirects) and returns an untyped `stdClass` whose
`body` changes type with the content-type. When done, every request has connect and total
timeouts that callers can override, headers come from the final response only, JSON bodies
are easy to send, transport failures throw, and callers get a typed `spl\HttpResponse`.

## Context

Phase 2 lane `http-client` of a seven-PR clean-up; the user settled the scope (see Decisions,
`user` rows). Area: `src/Http.php`, a new response class, `composer.json` (`ext-curl`).

Codebase facts:
- `src/Http.php` (199 lines): `__callStatic` forwards `get/post/put/delete/options` to
  `request(string $method, string $url, array $headers = [], string|array $body = '')`,
  which sets `CURLOPT_HEADER`, splits the raw response on the first `"\r\n\r\n"`, auto-decodes
  JSON bodies and returns `(object) [http_version, status_code, status_message, headers, body]`.
  No timeouts, no redirect handling, HEAD sent via `CURLOPT_CUSTOMREQUEST` (curl then waits
  for a body that never comes). `curl_close()` was removed in Phase 1.
- `parse_status_line()` only accepts `HTTP/<d>.<d> <code> <reason>`, so `HTTP/2 200` (what
  curl negotiates over HTTPS with an h2 server; local libcurl 8.14.1 has HTTP/2) throws.
  `parse_headers()` given a header block ending in CRLF (or any line without `:`) emits an
  "Undefined array key 1" warning.
- No other code in src/ or tests/ uses `Http`. No `tests/HttpTest.php` exists yet.
- `php -S` never sends `100 Continue` (probed: curl waits its 1 s expect timeout, then the
  response has a single header block), so the 100-continue case needs a tiny raw-socket
  fixture server. libcurl 8.14 only sends `Expect: 100-continue` for bodies over 1 MiB.
- phpstan baseline has one `src/Http.php` entry (CUSTOMREQUEST non-empty-string); unmatched
  entries are fine, new ignores are not allowed.

Constraints from the Phase 2 brief: PHP ^8.4, `declare(strict_types=1)`, php-cs-fixer
(PER-CS2.0, same-line braces, `else`/`catch` on next line) over src/ and tests/, phpstan
level 8 strict over src/, PHPUnit 12 with fail-on-warning/notice/deprecation/risky and
strict output. Don't touch README/AGENTS/CONTRIBUTING. No internet in tests.

## Baseline

- Check commands: `composer cs-check`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `composer test` (all via `composer check`)
- Setup: `composer install`
- Setup leftovers: `vendor/` (gitignored), `.php-cs-fixer.cache`, `.phpunit.cache` (gitignored)
- Failing before this work: none (cs clean, phpstan 0 errors, 60 tests passing)
- Not included: `prompts/` (untracked in /workspace, unrelated)

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | Timeouts | `CURLOPT_CONNECTTIMEOUT` 10 s and `CURLOPT_TIMEOUT` 30 s on every request, integer seconds | no timeouts (today); `_MS` variants | brief: no request may hang forever; the brief names the second-based options | user |
| D2 | Per-request override API | trailing `array $options = []` on `request()` and every verb helper; keys `connect_timeout`, `timeout`, `follow_redirects`, `json`; defaults in public const `Http::DEFAULTS`; unknown key or wrong type throws `InvalidArgumentException` before any I/O | extra positional params; a fluent builder; silently ignoring unknown keys | one extensible slot keeps the helpers' signatures short; rejecting typos (`timout`) avoids a silently ignored timeout | user (shape: self) |
| D3 | Header collection | `CURLOPT_HEADERFUNCTION` collects lines; a line starting `HTTP/` starts a new block and discards earlier lines, so only the final response's headers and status survive | splitting on `"\r\n\r\n"` (today) | interim `100 Continue` and redirect hops each emit a full header block | user |
| D4 | Follow redirects by default? | No. `follow_redirects => true` opts in, max 5 hops; redirect protocols left at curl's default (http, https, ftp, ftps since 7.65.2) | follow by default (Guzzle/Symfony style) | keeps today's behaviour; with following on, curl re-sends `CURLOPT_CUSTOMREQUEST` methods (PUT/DELETE/OPTIONS) unchanged to the new location, which is unsafe as a silent default. `CURLOPT_REDIR_PROTOCOLS_STR` is only defined when PHP is built against libcurl >= 7.85 (Ubuntu 22.04 ships 7.81), so it isn't set | self |
| D5 | How the HTTP method is set | GET: curl default; HEAD: `CURLOPT_NOBODY`; POST: `CURLOPT_POST`/`POSTFIELDS`; others: `CURLOPT_CUSTOMREQUEST` | `CURLOPT_CUSTOMREQUEST` for everything (today) | HEAD via CUSTOMREQUEST makes curl wait for a body; CUSTOMREQUEST POST breaks curl's POST-to-GET switch on 301/302/303 when following redirects | self |
| D6 | Verb helpers | explicit static methods `get/head/delete/options(url, headers, options)` and `post/put(url, headers, body, options)`; remove `__callStatic`; add `HEAD` constant | keep `__callStatic` | typed signatures for phpstan/IDEs and named args (`options: [...]`); `head()` is needed for the HEAD case in scope | self |
| D7 | JSON request shape | `json` option: value is `json_encode`d with `JSON_THROW_ON_ERROR`; adds `Content-Type: application/json` unless a `Content-Type` header is already given (case-insensitive); combining with a non-empty `$body` throws `InvalidArgumentException` | `postJson()`/`putJson()` helpers; auto-encoding array bodies (array bodies already mean multipart form data) | works for every method through the D2 slot without new helpers | user (shape: self) |
| D8 | Response class | `final readonly class spl\HttpResponse` in `src/HttpResponse.php`; public `http_version`, `status_code`, `status_message`, `headers`, `body` (raw string); `json(): mixed` (assoc arrays, `JSON_THROW_ON_ERROR`), `ok(): bool` (200-299) | `spl\http\Response` (same short name as `spl\web\Response`, clashes in `use` lists) | utilities live flat in `spl\`; the name can't be confused with the web response | user (name: self) |
| D9 | Transport failure | after `curl_exec`, `curl_errno() !== 0` throws `RuntimeException("Curl error: <curl_error>", <errno>)`; URL not included | message only (today, code 0) | errno as code lets callers tell a timeout (28) from a refused connection (7); URLs may carry credentials | user (code: self) |
| D10 | Status line / header helper fixes | `parse_status_line()` accepts `HTTP/2` and a missing reason phrase (message `''`); `parse_headers()` skips lines without `:`; both stay public with the same return shapes | leave as is | the new header collector feeds them real curl lines, including h2 status lines | self |
| D11 | Test seam | local servers only: `php -S` with fixture router `tests/fixtures/http/router.php`, plus raw-socket `tests/fixtures/http/continue-server.php` for `100 Continue`; both started in `setUpBeforeClass()`, tests skip if they can't start | mocking curl; hitting the internet | `php -S` can't send interim responses (probed); real sockets exercise the real curl path | self |

## Requirements

- MUST apply a connect timeout (default 10 s) and total timeout (default 30 s) to every request, overridable per request via `$options['connect_timeout']` / `$options['timeout']` (non-negative int seconds).
  - AC: `Http::get(<slow endpoint sleeping 2 s>, options: ['timeout' => 1])` throws `RuntimeException` with code `CURLE_OPERATION_TIMEDOUT` (28) in under 2 s.
  - AC: `Http::DEFAULTS` is `['connect_timeout' => 10, 'timeout' => 30, 'follow_redirects' => false]`.
  - AC: an unknown option key, a non-int / negative timeout, a non-bool `follow_redirects` or an empty method throws `InvalidArgumentException` without making a request.
- MUST return headers and status of the final response only.
  - AC: a >1 MiB POST to the continue server (which answers `100 Continue` then `201 Created` with `X-Final: yes`) returns status 201, `x-final` header, body = byte count.
  - AC: with `follow_redirects => true`, GET `/redirect` (302 with `X-Redirect: yes` → `/json`) returns 200, JSON content-type, no `x-redirect` or `location` header.
  - AC: HEAD `/json` (server sends `Content-Length` > 0 and no body) returns 200, the content-type header and body `''`; 204 `/empty` returns body `''`.
  - AC: duplicate headers join with `,`; `set-cookie` is a list; header names lowercased.
- MUST NOT follow redirects unless `follow_redirects` is true; MUST stop after 5 hops.
  - AC: default GET `/redirect` returns 302 with `location` header; `/loop` with following on throws `RuntimeException` code `CURLE_TOO_MANY_REDIRECTS` (47).
  - AC: with following on, POST to `/see-other` (303 → `/echo`) arrives at `/echo` as GET.
- MUST send JSON bodies via `$options['json']`.
  - AC: POST with `json => ['a' => 1]` arrives with body `{"a":1}` and `Content-Type: application/json`; an explicit `Content-Type: application/vnd.x+json` header is kept as is; `json` plus non-empty `$body` throws `InvalidArgumentException`; an unencodable value (`NAN`) throws `JsonException`.
- MUST return `spl\HttpResponse` with raw-string `body`, `json()` and `ok()`.
  - AC: GET `/json` returns an `HttpResponse` whose `body` is the raw JSON string and `json()` returns the decoded assoc array; `ok()` is true for 200/204/299, false for 199/302/404/500; `json()` on invalid JSON throws `JsonException`; assigning a property throws `Error`.
- MUST throw `RuntimeException` carrying the curl errno on transport failure.
  - AC: GET to a closed local port throws `RuntimeException` with code `CURLE_COULDNT_CONNECT` (7).
- MUST keep `parse_headers()`/`parse_status_line()` public, accepting `HTTP/2` status lines and header blocks with a trailing CRLF without warnings.
  - AC: `parse_status_line('HTTP/2 200')` returns `['http_version' => '2', 'status_code' => 200, 'status_message' => '']`; `parse_headers("HTTP/1.1 200 OK\r\nA: b\r\n")` returns `['http' => [...], 'a' => 'b']`; an invalid status line still throws `RuntimeException`.
- MUST declare `ext-curl` in composer.json `require`, with composer.lock's content-hash updated and no dependency changes.
  - AC: `composer validate --strict` passes; `git diff master -- composer.lock` changes only `content-hash` and the `platform` list.

Out of scope: persistent curl share handles, retries, associative header arrays, sub-second timeouts, response decompression, other lanes' files, README/AGENTS docs.

## Design

### Overview

`Http::request()` validates `$options` against `Http::DEFAULTS`, builds the body (JSON or
given), configures curl (method per D5, timeouts, redirects, header callback), runs it,
throws on curl errors, and builds an `HttpResponse` from the last header block plus the
raw body.

### Code structure

- `src/Http.php` (changed): `DEFAULTS` const, `HEAD` const, explicit verb helpers,
  `request(string $method, string $url, array $headers = [], string|array $body = '', array $options = []): HttpResponse`,
  `parse_headers()`, `parse_status_line()`; private helpers as needed (option validation,
  header-line parsing shared by `parse_headers()` and `request()`).
- `src/HttpResponse.php` (new): the readonly response class (D8). `headers` typed
  `array<string, string|list<string>>`.
- `tests/HttpTest.php` (new): helper unit tests + local-server integration tests; follows
  `tests/StrTest.php` (final class, `#[DataProvider]` with static providers).
- `tests/HttpResponseTest.php` (new): `ok()`, `json()`, readonly.
- `tests/fixtures/http/router.php` (new): `php -S` router with `/json` (sends an explicit
  `Content-Length`, so a HEAD sent as a custom request would wait for a body), `/headers`,
  `/empty` (204), `/echo` (method, content-type, raw body as JSON), `/redirect`,
  `/see-other`, `/loop`, `/slow`.
- `tests/fixtures/http/continue-server.php` (new): accepts connections forever; on
  `Expect: 100-continue` writes `HTTP/1.1 100 Continue`, reads `Content-Length` bytes,
  replies `201 Created`, `X-Final: yes`, body = byte count.
- `composer.json` / `composer.lock`: `ext-curl`.

### Error handling

| Failure | Response |
|---|---|
| unknown option / bad option type / `json` with `$body` / empty method | `InvalidArgumentException`, before `curl_init` |
| `json` value not encodable | `JsonException` from `json_encode` propagates |
| connect failure, timeout, too many redirects, any curl error | `RuntimeException("Curl error: …", errno)` |
| response with no status line | `RuntimeException("Invalid HTTP response: no status line")` |
| `HttpResponse::json()` on non-JSON body | `JsonException` propagates |

### Non-functional

- Every request is bounded by `connect_timeout + timeout` (≤ 30 s total by default).
- Redirects only when opted in, max 5 (`CURLOPT_MAXREDIRS`); curl's default redirect protocols already exclude `file://`.
- Error messages never include the URL (may contain credentials).

## Milestones

### [x] M1 — Typed response with final-block header parsing

- **Approach:** Bug-first: write `tests/HttpTest.php` cases for the HTTP/2 status line,
  trailing-CRLF `parse_headers()`, 100-continue and HEAD against the old code and record
  that they fail. Then add `src/HttpResponse.php`; rewrite `request()` per D3/D5/D9 (no
  `CURLOPT_HEADER`; `RETURNTRANSFER`; header callback reset on `HTTP/` lines); fix the two
  helpers per D10; replace `__callStatic` with explicit helpers per D6, without the `$options`
  parameter (M2 adds it to `request()` and every helper). Fixture servers started with `proc_open`
  (array command, `PHP_BINARY`, stdio to `/dev/null`) on a free port from binding
  `tcp://127.0.0.1:0`; wait up to 5 s for `fsockopen` to succeed; `proc_terminate` in
  `tearDownAfterClass()`; a `url()` helper calls `markTestSkipped()` when a server is down.
  Add `ext-curl` to composer.json and run `composer update --lock`.
  ⚠️ Keep router output exact (no stray newline before `<?php`).
- **Test seam:** `Http::get/head/post`, `Http::parse_headers/parse_status_line`, `HttpResponse`.
- **Edge cases:** 204/HEAD → body `''`; `100 Continue` → dropped; trailing CRLF / no-colon lines → skipped; refused connection → `RuntimeException` code 7.
- **Deliverable:** `Http::get()` against the local server returns an `HttpResponse` with the final block's headers.
- **Verify:** `vendor/bin/phpunit tests/HttpTest.php tests/HttpResponseTest.php` passes; `composer check` passes; `composer validate --strict` passes.
- **Outcome:** Added `src/HttpResponse.php`, rewrote `Http::request()` (header callback,
  D5 method mapping, errno exceptions), explicit verb helpers incl. `head()`, fixed
  `parse_status_line()`/`parse_headers()`, fixtures `tests/fixtures/http/{router,continue-server}.php`,
  `tests/HttpTest.php`, `tests/HttpResponseTest.php`, `ext-curl` in composer.json/lock.
  Bug-first run against the old code (`vendor/bin/phpunit tests/HttpTest.php`): 8 errors,
  2 failures, 1 warning — `HTTP/2 200` and `HTTP/1.1 204 ` rejected, trailing-CRLF and
  no-colon `parse_headers()` raised "Undefined array key 1" + TypeError, 100-continue POST
  returned status 100, refused connection had code 0; old `request('HEAD', …)` threw
  "end of response with 28 bytes missing". After: HttpTest + HttpResponseTest 27 tests OK;
  cs-check 0 files; phpstan `[OK] No errors`; `composer test` 87 tests OK; `composer validate --strict`
  valid; no fixture processes left running. phpstan run with a private `tmpDir`
  (`-c /tmp/phpstan-006.neon`) because parallel agents corrupt the shared `/tmp/phpstan` cache.

### [x] M2 — Timeouts, options and opt-in redirects

- **Approach:** `DEFAULTS` const; validate `$options` (keys must exist in `DEFAULTS` or be
  `json`; timeouts non-negative int; `follow_redirects` bool); set
  `CURLOPT_CONNECTTIMEOUT`/`CURLOPT_TIMEOUT` always; when following set
  `FOLLOWLOCATION`, `MAXREDIRS` 5. Router `/slow`
  sleeps 2 s (php -S is single-threaded, so later tests wait ≤ 1 s; acceptable).
- **Test seam:** `Http::get/post` with `options:`.
- **Edge cases:** typo key → `InvalidArgumentException`; `timeout => '5'` → `InvalidArgumentException`; loop → code 47.
- **Deliverable:** a slow endpoint fails after the per-request timeout; redirects followed only on request.
- **Verify:** `vendor/bin/phpunit tests/HttpTest.php` passes with the timeout test under 2 s; `composer check` passes.
- **Outcome:** `Http::DEFAULTS` + `MAX_REDIRECTS` consts, private `resolveOptions()`,
  `$options` on `request()` and all six helpers, timeouts set on every request,
  `FOLLOWLOCATION`/`MAXREDIRS` when opted in. Tests: defaults, 7 invalid-option rows,
  empty method, 1 s timeout (code 28, asserted < 2 s), redirect not followed by default,
  followed redirect keeps final headers only, loop → code 47, POST → 303 arrives as GET.
  `vendor/bin/phpunit tests/HttpTest.php`: 29 tests OK in 2.2 s; cs-check 0 files;
  phpstan `[OK] No errors`; `composer test` 101 tests OK.

### [x] M3 — JSON request bodies

- **Approach:** `json` option per D7; content-type check is a case-insensitive
  `str_starts_with(strtolower($header), 'content-type:')` over the header list.
- **Test seam:** `Http::post(..., options: ['json' => ...])` against `/echo`.
- **Deliverable:** JSON POST arrives encoded with the right content type.
- **Verify:** `vendor/bin/phpunit tests/HttpTest.php` passes; `composer check` passes.
- **Outcome:** `json` option in `request()` (validated by `resolveOptions()`, encoded with
  `JSON_THROW_ON_ERROR`, Content-Type added unless present). Tests: POST arrives as
  `{"a":1,"b":[true,null]}` with `application/json`; PUT keeps a lowercase explicit
  `content-type: application/vnd.x+json`; `json` + body → `InvalidArgumentException`; `NAN`
  → `JsonException`. `vendor/bin/phpunit tests/HttpTest.php`: 33 tests OK; cs-check 0 files;
  phpstan `[OK] No errors`; `composer test` 105 tests OK.

## Deviations

## Risks

- With `follow_redirects` on, PUT/DELETE/OPTIONS are re-sent with the same method to the
  redirect target (curl `CUSTOMREQUEST` semantics; PHP 8.4 has no `CURLFOLLOW_OBEYCODE`).
  Documented in the `request()` docblock; opt-in only.
- The connect timeout firing is not testable without a blackholed address; only its
  validation and wiring are covered.
- Breaking changes for callers: `body` is always the raw string; return type is
  `HttpResponse`; unknown static calls now raise `Error` instead of `BadMethodCallException`.

## Review

- Plan review: 1 pass, self-conducted (no subagent tool in this session) per
  references/PLAN-REVIEW.md. Fixed: ⚠️ M1 was ambiguous about the `$options` slot (now added
  in M2 only); ⚠️ `CURLOPT_REDIR_PROTOCOLS_STR` is undefined on PHP builds against libcurl
  < 7.85, so D4 drops it and relies on curl's default redirect protocols; ℹ️ empty-method and
  `follow_redirects` type validation had no AC (added); ℹ️ the router's HEAD trigger
  (explicit `Content-Length`) was unstated (added). No ❌, so no second pass.
- Code review: self-conducted (no subagent tool in this session) per
  references/CODE-REVIEW.md, roles `general` and `risk` (risk applies: public library API,
  external HTTP input, per-request code). One round, no ❌.
  - Fixed ℹ️ (general): `resolveOptions()` read `self::DEFAULTS` while `request()` used
    `static::MAX_REDIRECTS`; now both use `static::` like the rest of the class.
  - Fixed ℹ️ (general): `options()` and the "GET with a body keeps GET" branch of D5 had no
    test; added `testOptionsUsesCustomMethod` and `testGetWithBodyKeepsGetMethod`.
  - Fixed ℹ️ (risk): breaking changes were incomplete; added the 30 s default total
    timeout, errno exception codes, and positional `$body` on get/delete/options now
    hitting the `$options` slot (TypeError) to the PR's Breaking changes list.
  - Rejected (risk): Authorization/Cookie headers leaking on followed cross-host redirects
    — libcurl ≥ 7.58 / 7.83 already drops custom `Authorization`/`Cookie` headers on
    host change, and following is opt-in (D4).
  - Rejected (general): `freePort()` race (port reused between release and use) — the
    window is microseconds on loopback and a clash fails loudly, not silently.
  - Rejected (general): Content-Type scan doesn't stop early — equal alternative, header
    lists are a handful of entries.
  - Spec check: every MUST maps to a passing test (see M1-M3 Outcomes) except that the
    *default* timeouts being applied is verified by code reading only (a test would
    take 30 s); `ext-curl` verified by `composer validate --strict`.
  - Checks after fixes: `composer check` → cs 0 files, phpstan `[OK] No errors`, 107 tests
    OK; `tests/HttpTest.php` + `tests/HttpResponseTest.php` run 3× in a row, 47 tests OK each.
- Merge risk: Medium: consumers of `spl\Http` that read `$response->body` as a decoded
  array or rely on unlimited request time break, but there are no callers in this repo, the
  break is listed in the PR, and every failure surfaces as an exception or type error
  rather than wrong data.

## Outcome

Delivered `spl\HttpResponse` (readonly, `json()`, `ok()`) and a reworked `spl\Http`:
connect/total timeouts (10 s / 30 s) on every request with per-request `$options`
overrides, header collection via `CURLOPT_HEADERFUNCTION` keeping only the final response,
opt-in redirects (max 5), a `json` request option, `head()`, errno-coded `RuntimeException`
on transport failure, HTTP/2-tolerant `parse_status_line()` and warning-free
`parse_headers()`, plus `ext-curl` in composer.json/lock. Evidence: `composer check` → cs 0
files, phpstan `[OK] No errors`, 107 tests OK; bug-first failures recorded in M1.
Not verified: the connect timeout firing (needs a blackholed address) and the 30 s default
total timeout elapsing (verified by reading `request()` only).

PR: https://github.com/simon-downes/spl/pull/6
CI: none (the repository has no CI checks)

## Follow-ups

- Accept associative header arrays (`['Accept' => 'application/json']`) in `Http` helpers.
- Consider `CURLFOLLOW_OBEYCODE` (PHP 8.5 / libcurl 8.13) so followed 303s switch
  PUT/DELETE to GET.
- Phase 3 docs: README/AGENTS `Http` section (see the PR's "For the docs PR" notes).