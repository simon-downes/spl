# 003 — Request and Response hardening

Status: done
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

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | How fromGlobals() derives the path | `parse_url(..., PHP_URL_PATH)`, fallback `/` | `str_replace` of the query string (today) | str_replace leaves `/a?` for an empty query string and needs QUERY_STRING to exist | user |
| D2 | Missing QUERY_STRING / HTTP_HOST | QUERY_STRING treated as `''` (no warning); host falls back to SERVER_NAME, then `''` | warn / TypeError (today) | every `php -S` request without a query string logs two warnings | user |
| D3 | When a body is JSON | media type (content-type before `;`, trimmed, case-insensitive) equals `application/json` | exact header match (today) | `application/json; charset=utf-8` is common and is ignored today | user |
| D4 | Empty or invalid JSON body | invalid non-empty JSON: `WebException::badRequest` (400). Empty or whitespace-only (JSON whitespace: space, tab, CR, LF) body: "no body" for every method, no decode, `getBody()` returns the raw string (`''` stays `''`, not `[]`), `lookup()` returns the default (revised post-PR review) | JsonException (500); 400 for empty bodies too (original D4) | client error, not a server fault; many API clients send `Content-Type: application/json` on bodiless GETs, which got a 400 on any `lookup()` miss. `''` kept over `[]` so a JSON-typed empty body looks the same as any other empty body | user |
| D5 | Where JSON parsing happens | lazily on first `getBody()`/`lookup()`, result cached | in the constructor | constructor runs in `SPL::run()` outside `App::handle()`'s try/catch, so a throw there is a 500; App.php is owned by router-app | user |
| D6 | Status code handling | keep the STATUSES whitelist; add 308 Permanent Redirect, 422 Unprocessable Content, 429 Too Many Requests | accept any 100-599 code | user wants unknown codes to keep failing | user |
| D7 | json() encoding failure | `JSON_THROW_ON_ERROR` (JsonException with PHP's message) | `JSON_INVALID_UTF8_SUBSTITUTE`; manual false check | user wants invalid UTF-8 to fail, with a meaningful message | user |
| D8 | Cookie defaults | HttpOnly true, SameSite Lax, set via the `setcookie()` options array | positional `setcookie()` with no flags (today) | cookies set by apps are session-style and shouldn't be script-readable by default | user |
| D9 | Escaping in fromThrowable() | `htmlspecialchars(ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` | raw message (today) | verified reflected XSS via `WebException::badRequest('<script>...')` | user |
| D10 | content-length type | cast to string in setBody() | widen the headers type | headers are documented and sent as strings; removes the baseline'd error | user |
| D11 | Path parsing input to parse_url | prefix `http://localhost` when REQUEST_URI starts with `/` (otherwise plain `parse_url`, e.g. absolute-form URIs), then take PHP_URL_PATH; `/` when the result is not a non-empty string | plain `parse_url($uri)` | verified: plain parse_url returns `false` for `/a:1` (would route to `/`) and `/y` for `//x/y`; the prefixed form returns the right path for both, and still drops `?query` and `#fragment` | self |
| D12 | Cookie API shape for secure/httponly/samesite | append `bool $secure = false, bool $httponly = true, string $samesite = 'Lax'` to `setCookie()` after `$domain` (named args work) | an `array $options` param; a separate setter | existing positional calls keep working unchanged; matches the setter style already used; named args give `setCookie('s', $id, secure: true)` | self |
| D13 | Default for secure | `false` | `true`; infer from request | Response has no request; `secure` true by default would silently drop cookies on `php -S` / plain-http dev setups this prototyping library targets | self |
| D14 | Invalid samesite value | throw `InvalidArgumentException` in setCookie() for anything but Strict/Lax/None (case-insensitive, stored canonical) | pass through to setcookie() | fails at the call site instead of during send(), after output may have started | self |
| D15 | What a valid JSON body becomes | the decoded value of any JSON type (`{}` -> `[]`, scalars as scalars), assoc arrays for objects | keep raw string when decoded value is falsy (today) | today `{}`, `[]`, `0` and `false` bodies stay raw strings, so `lookup()` silently misses | self |
| D16 | Which media types count as JSON | only `application/json` | also `+json` suffix types | user scope names `application/json` only; `+json` can be added later | self |
| D17 | Where to test fromGlobals | unit tests setting `$_SERVER`/`$_POST`/`$_COOKIE`, restored in tearDown | `#[RunInSeparateProcess]` | no process-wide constants involved; superglobals are restorable | self |
| D18 | Where to verify the 400-through-App path | manual `php -S` smoke test against a temp app outside the worktree | an AppTest in tests/web | App.php/AppTest are router-app's lane; a smoke test exercises SPL::run -> fromGlobals -> App::handle for real | self |
| D19 | Content headers without the `HTTP_` prefix | `fromGlobals()` maps `$_SERVER['CONTENT_TYPE']`/`['CONTENT_LENGTH']` to the content-type/content-length headers when the `HTTP_` variants are absent (`HTTP_` wins if both exist) | only `HTTP_*` keys (before) | Apache/CGI SAPIs expose them only unprefixed, so JSON bodies were never detected there (added post-PR review) | user |
| D20 | How to test send()'s cookie options | extract a protected static `Response::cookieOptions()` used by `send()`, tested via ReflectionMethod | separate-process / `php -S` test | `headers_list()` is always empty in CLI, so send() itself can't be observed in PHPUnit; the extracted method is the whole mapping (added post-PR review) | user |

## Requirements

- MUST derive `Request::fromGlobals()`'s path from REQUEST_URI's path component only.
  - AC: REQUEST_URI `/users/5?x=1` -> `/users/5`; `/a?` -> `/a`; `/p/12:30` -> `/p/12:30`; `//x/y` -> `//x/y`; missing REQUEST_URI -> `/`.
- MUST NOT raise a PHP warning when QUERY_STRING is absent, and MUST still parse it when present.
  - AC: fromGlobals() with no QUERY_STRING passes under failOnWarning; with `a=1&b[]=2` lookup returns `'1'` and `['2']`.
- MUST use HTTP_HOST, else SERVER_NAME, else `''` as the host.
  - AC: three fromGlobals() cases return `example.com`, `server.local`, `''`.
- MUST decode the body as JSON when the content-type media type is `application/json`, regardless of parameters, whitespace or case.
  - AC: `application/json; charset=utf-8` and ` Application/JSON ` bodies decode; `text/plain` stays a raw string.
- MUST throw `WebException` with code 400 from `getBody()` and `lookup()` (not from the constructor) for a non-empty invalid JSON body.
  - AC: constructing with body `{bad` does not throw; `getBody()` and `lookup('k')` throw WebException code 400; `lookup()` of a key present in the query does not read the body; `php -S` smoke test with bad JSON gets HTTP 400, not 500.
- MUST treat an empty or whitespace-only body with a JSON content-type as no body, for every method (revised post-PR review, D4).
  - AC: GET/DELETE/POST/PUT with `Content-Type: application/json` and body `''` or whitespace: `lookup('limit', 10)` returns 10, `getBody()` returns the raw body; `php -S` GET with the header and no body returns 200.
- MUST read the content type and length from unprefixed `CONTENT_TYPE`/`CONTENT_LENGTH` when the `HTTP_` variants are absent (D19).
  - AC: fromGlobals() with only `CONTENT_TYPE`/`CONTENT_LENGTH` returns them from `getHeader()`; with both, the `HTTP_` value wins.
- MUST decode at most once per Request and return the same value on repeat calls.
  - AC: a JSON string body `"abc"` returns `'abc'` from two consecutive `getBody()` calls.
- MUST accept 308, 422 and 429 in `setStatus()` with their standard reason phrases, and still reject unknown codes.
  - AC: `Response::html('', 429)->getStatus()` is `['code' => 429, 'message' => 'Too Many Requests']`; 308 isRedirect(); 299 throws.
- MUST make `Response::json()` throw `JsonException` on unencodable data, with PHP's error message.
  - AC: `Response::json(['x' => "\xB1\x31"])` throws JsonException with message containing `Malformed UTF-8`.
- MUST send cookies with the `setcookie()` options form, HttpOnly true and SameSite Lax by default, with secure settable; existing positional calls keep working.
  - AC: `setCookie('s', 'v')` stores `secure false, httponly true, samesite 'Lax'`; `setCookie('s', 'v', secure: true, samesite: 'strict')` stores secure true, samesite `Strict`; `samesite: 'bogus'` throws InvalidArgumentException; `send()` passes the stored options via `cookieOptions()`, whose output is asserted exactly (`testCookieOptionsPassedToSetcookie`), plus the smoke test's Set-Cookie header.
- MUST HTML-escape the exception message in `Response::fromThrowable()`.
  - AC: `badRequest('<script>alert(1)</script>')` body is `<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1>`.
- MUST store `content-length` as a string.
  - AC: `Response::text('abc')->getHeader('content-length') === '3'`; phpstan passes with 0 errors.

Out of scope: X-Forwarded-For / client IP handling (unchanged), App.php handler return
values and HEAD support (router-app), error.php escaping (error-handling), README/AGENTS
docs (Phase 3).

## Design

### Overview

Small, local fixes inside the three owned files. The one structural change is that Request
keeps the raw body and decodes JSON on first access, so the 400 is thrown inside the route
handler, where `App::handle()` already turns a WebException into a response.

### Code structure

- src/web/Request.php: `fromGlobals()` guards, unprefixed CONTENT_TYPE/CONTENT_LENGTH
  mapping (D19) and path parsing in a protected static `parsePath()`; new protected
  `bool $body_decoded` flag; decoding is inline in `getBody()` (skipped for empty or
  whitespace-only bodies, D4), guarded by the flag; `lookup()` returns the query value if
  set, else reads through `getBody()` and indexes it only when it's an array
  (`is_array($body) ? $body[$k] ?? $default : $default`); constructor no longer decodes.
  Media-type check in a protected `isJson()`.
- src/web/Response.php: STATUSES additions; `json()` flag; `fromThrowable()` escaping;
  `setBody()` cast; `setCookie()` new params, validation and stored keys; `send()` passes
  the options array built by a protected static `cookieOptions()` (D20); cookie property
  docblock gains the new keys.
- phpstan-baseline.neon: the now-unmatched Response `$headers` entry removed.
- src/web/WebException.php: no change expected.
- tests/web/RequestTest.php, tests/web/ResponseTest.php: extended (follow the existing
  style in those files and `tests/util/EnvTest.php` for setUp/tearDown restore).

### Data flow

- Happy path: `SPL::run` -> `Request::fromGlobals()` (no decode) -> `App::handle` -> handler
  calls `getBody()` -> decode, cache, return.
- Bad JSON: handler calls `getBody()` -> WebException 400 -> `App::handle` catch ->
  `Response::fromThrowable` (escaped message, 400) -> sent; not a server error, so no
  SPL::error page.
- A handler that never reads the body never triggers the error (accepted).

## Milestones

### [x] M1 — fromGlobals handles missing server vars and parses the path

- **Approach:** in `Request::fromGlobals()` use `$_SERVER['QUERY_STRING'] ?? ''`,
  host `HTTP_HOST ?? SERVER_NAME ?? ''`, and the D11 path parsing with `/` fallback for a
  missing REQUEST_URI or a non-string/empty result. Tests leave `$_POST` empty
  (`php://input` is `''` in CLI) or set it, and save/restore `$_SERVER`, `$_POST`, `$_COOKIE` in
  setUp/tearDown. Write the missing-QUERY_STRING and missing-HTTP_HOST tests first and see
  them fail.
- **Test seam:** `Request::fromGlobals()` and its getters.
- **Edge cases:** `/a?` -> `/a`; `/a:1` -> `/a:1`; `//x/y` -> `//x/y`; missing REQUEST_URI -> `/`.
- **Deliverable:** fromGlobals() works with a minimal `$_SERVER`.
- **Verify:** `vendor/bin/phpunit tests/web/RequestTest.php` passes with no warnings; full checks pass.
- **Outcome:** `fromGlobals()` defaults QUERY_STRING to `''`, host to HTTP_HOST/SERVER_NAME/`''`, and uses new `Request::parsePath()`. New tests failed first (TypeError on null host, "Undefined array key QUERY_STRING" warning), then `composer check`: cs 0, phpstan 0 errors, 76 tests OK.

### [x] M2 — Lazy JSON body decoding with 400 on bad JSON

- **Approach:** remove decoding from the constructor; `getBody()` decodes inline, once
  (flag): for a non-empty (after trimming JSON whitespace) string body with a JSON media
  type, `json_decode(..., true, flags: JSON_THROW_ON_ERROR)`, converting JsonException to
  `WebException::badRequest('Invalid JSON request body: ' . message)` and leaving the flag
  unset so later calls fail the same way. `lookup()` returns `$this->query[$k]` if set,
  otherwise reads `getBody()` and returns `$body[$k] ?? $default` only if the body is an
  array, else `$default`. Tests first: charset parameter body decodes (fails today); bad
  JSON constructs but getBody throws 400 (fails today).
- **Test seam:** `new Request(...)` then `getBody()` / `lookup()`.
- **Edge cases:** bad JSON but key in query -> `lookup()` returns the query value; `{}` -> `[]`; `"abc"` -> `'abc'` twice; `''` -> no body, returned as `''` (revised post-PR review, D4; originally 400); array body with JSON content-type -> unchanged; non-JSON content-type string -> unchanged.
- **Deliverable:** JSON bodies decode by media type; bad JSON is a 400 from the handler.
- **Verify:** RequestTest passes; smoke test: `php -S` temp app with a route returning `$request->getBody()`; `curl -X POST -H 'Content-Type: application/json; charset=utf-8' -d '{"a":1}'` -> 200 `{"a":1}`; `-d '{bad'` -> 400; no "Undefined array key" in the server log.
- **Outcome:** Constructor no longer decodes; `getBody()` decodes once via new `isJson()` media-type check and converts JsonException to `WebException::badRequest('Invalid JSON request body: ...')`; `lookup()` reads the body only when the query lacks the key and returns the default for non-array bodies. New tests failed first (JsonException from constructor, charset/falsy bodies left raw). `composer check`: cs 0, phpstan 0, all tests OK. Smoke test (`php -S` on a temp app under /tmp, routes use App's `METHOD:/path` syntax): charset JSON -> 200 `{"body":{"a":1},...}`; `{bad` and empty -> 400 `Invalid JSON request body: Syntax error`; `/path/12:30?q=x` -> path `/path/12:30`; server log has no warnings.

### [x] M3 — Response statuses, JSON errors, escaping and content-length

- **Approach:** add 308/422/429 to STATUSES; `json_encode($data, JSON_THROW_ON_ERROR)`
  with `@throws JsonException`; `htmlspecialchars` in fromThrowable(); `(string) strlen()`
  in setBody(). Tests first for 429, json malformed UTF-8 and XSS escaping.
- **Test seam:** Response factories and `getStatus()`/`getBody()`/`getHeader()`.
- **Deliverable:** listed Response bugs fixed.
- **Verify:** `vendor/bin/phpunit tests/web/ResponseTest.php` passes; phpstan 0 errors.
- **Outcome:** STATUSES gains 308/422/429; `json()` uses JSON_THROW_ON_ERROR; `fromThrowable()` escapes with htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, UTF-8); content-length cast to string (the phpstan baseline entry for Response.php is now unmatched, which phpstan accepts; removed post-PR review). New tests failed first ("308 is not a valid HTTP status code", TypeError from json(), raw `<script>`, int 3). `composer check`: cs 0, phpstan 0, 101 tests OK.

### [x] M4 — Cookie options with HttpOnly and SameSite defaults

- **Approach:** per D12-D14; stored cookie array gains `secure`, `httponly`, `samesite`;
  `send()` calls `setcookie($name, $value, [expires, path, domain, secure, httponly,
  samesite])`. State tests via `getCookie()`/`getCookies()`; send() is not called in the
  test process (header() warns after output in CLI, verified).
- **Test seam:** `setCookie()` + `getCookie()`.
- **Deliverable:** cookies default to HttpOnly + SameSite=Lax; secure settable.
- **Verify:** ResponseTest passes; smoke test route setting a cookie shows
  `Set-Cookie: ...; HttpOnly; SameSite=Lax` in `curl -i`.
- **Outcome:** `setCookie()` gains `secure`, `httponly`, `samesite` params (samesite validated and stored as Strict/Lax/None); `send()` uses the setcookie() options array. New tests failed first ("Unknown named parameter $secure", missing keys). `composer check`: cs 0, phpstan 0, 106 tests OK. Smoke test: `/cookie` sends `Set-Cookie: s=v; path=/; HttpOnly; SameSite=Lax` and `t=w; ...; Max-Age=60; path=/; secure; HttpOnly; SameSite=Strict`; `/xss` -> 400 `<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1>`; `/limit` -> `HTTP/1.1 429 Too Many Requests`; server log clean.

## Deviations

- M2: `lookup()` returns the default when the body isn't an array (before, a raw string body
  was indexed as a string, so `lookup('0')` on body `abc` returned `a`). Follows from D15;
  no scope change.

## Risks

- Handlers that never read the body won't see a 400 for bad JSON (by design, D5).
- `samesite: 'None'` without `secure: true` is accepted but browsers reject such cookies;
  documented in the setCookie() docblock rather than enforced.
- Behaviour changes for consumers (cookie defaults, escaped messages, decoded falsy JSON
  bodies, exception types) are listed as breaking changes in the PR.

## Review

- Plan review: 1 pass, self-conducted (no subagent tool in this session) against
  PLAN-REVIEW.md. Findings fixed: ⚠️ lookup() 400 had no AC (added, plus the
  query-first case); ℹ️ D11 didn't say what happens for URIs not starting with `/`
  (added); ℹ️ M1 claimed `$_POST` must be set to avoid php://input (not needed in CLI;
  reworded); ℹ️ SameSite=None without Secure unrecorded (added to Risks). No ❌, so no
  second pass.
- Code review: 1 round, self-conducted (no subagent tool in this session) against
  CODE-REVIEW.md in both roles over `git diff fcd7a53...HEAD` (5 files).
  - `general`: ℹ️ test helper `makeJsonRequest()` lacked the `array<string, string>`
    type for `$query` (fixed). Checked: `lookup()` ternary/`??` precedence, decode-once flag
    stays unset on failure (tested), docblocks for constructor/getBody/lookup/setCookie
    match behaviour, consumers of changed APIs in src/ (`SPL::run()` calls
    `fromGlobals()`; App uses getPath/getMethod, `Response::html/json/fromThrowable`,
    `isServerError`, `send`): none need changes.
  - `risk` (run: external input and public library API): no findings in scope.
    Checked: `http://localhost` prefix can't turn a path into authority/userinfo (authority
    ends at the first `/` of the URI); samesite is whitelisted before reaching setcookie();
    the 400 message only carries PHP's JSON error text; behaviour changes are listed as
    breaking in the PR. Host-header trust and REQUEST_METHOD guarding are pre-existing and
    out of scope.
  - Spec check: every MUST traced to a passing test (RequestTest fromGlobals*/Json*/BadJson*/
    Lookup*, ResponseTest AddedStatus*/Json*/FromThrowable*/ContentLength*/Cookie*) and the
    `php -S` smoke test for the App-level 400 and Set-Cookie flags.
- Merge risk: Medium: per-request public APIs change behaviour (cookies now HttpOnly by
  default, bad JSON throws a 400 WebException from getBody()/lookup(), escaped error
  messages), which can surprise existing apps; limited by being local to three classes,
  covered by 106 tests plus a live smoke test, and easy to revert.

### Independent review (post-PR)

Review: general + risk roles over fcd7a53...1b5feb7. User decisions applied: D4 revised
(empty body is no body), D19 added (unprefixed content headers), D20 added (cookie
options test seam).

- ℹ️ phpstan-baseline.neon Response `$headers` entry unmatched: fixed, entry removed;
  phpstan 0 errors without it.
- ℹ️ `send()` cookie options mapping untested: fixed, mapping extracted to protected static
  `Response::cookieOptions()`; `ResponseTest::testCookieOptionsPassedToSetcookie` asserts
  the exact options array (all six setcookie() keys) for explicit and default cookies.
  Failed first (method missing).
- ℹ️ plan Design/M2 described `decodeBody()` and a `??`-chained `lookup()`: fixed, Code
  structure and M2 Approach now describe the inline decoding and the `is_array` guard.
- ❓ unprefixed CONTENT_TYPE/CONTENT_LENGTH (Apache/CGI): user decided to fix (D19);
  `fromGlobals()` maps them when the `HTTP_` variants are absent.
  `RequestTest::testFromGlobalsReadsUnprefixedContentHeaders` failed first (empty
  content-type); `testFromGlobalsPrefersHttpContentHeaders` covers precedence. Not run
  under real Apache/FPM.
- ❓ bodiless GET with `Content-Type: application/json` gets a 400 on a `lookup()` miss:
  user decided an empty or whitespace-only body is no body for every method (D4 revised);
  `getBody()` returns the raw body (`''`), `lookup()` the default; only non-empty invalid
  JSON is a 400. `RequestTest::testEmptyJsonBodyIsNoBody` (GET/DELETE/POST/PUT, `''` and
  whitespace) failed first with the 400; `badJsonProvider` now covers `{bad`, `{"a":`,
  ` {bad` and `"\0"` (still 400). Smoke test (`php -S`, port 8203, temp router script calling `Request::fromGlobals()`
  and `lookup()` directly): GET
  `/list?page=2` with the JSON header and no body -> 200 `{"limit":10,"page":"2",...}`;
  POST `{bad` -> 400 `Invalid JSON request body: Syntax error`; POST `{"limit":5}` -> 5;
  server log clean.
- ℹ️ (risk) `parse_url()` turns raw control characters into `_`: fixed, noted in the
  `parsePath()` docblock (verified: `/a\x01b%01` -> `/a_b%01`).
- ⚠️ (cross-PR) #5's AppTest asserts an int content-length: not fixed here; being fixed on
  #5's branch.
- (cross-PR) Request.php conflict with #7: resolved at merge time by taking this PR's
  version; no change here.

Checks after fixes: cs-check 0 fixable, phpstan 0 errors, 115 tests / 244 assertions OK.

## Outcome

Delivered M1-M4: robust `Request::fromGlobals()` (path via parse_url, no QUERY_STRING
warning, host fallback, unprefixed CONTENT_TYPE/CONTENT_LENGTH), lazy media-type JSON
decoding with 400 on invalid JSON (empty bodies count as no body), Response 308/422/429,
throwing `json()`, escaped `fromThrowable()`, string content-length, and cookie options
with HttpOnly + SameSite=Lax defaults. Evidence: cs 0 fixable, phpstan 0 errors, 115
tests / 244 assertions OK after the post-PR review fixes, and the `php -S` smoke tests
recorded in M2/M4 and the Review section. Not verified: behaviour behind real proxies or
non-`php -S` SAPIs (FPM/Apache), which populate REQUEST_URI/HTTP_HOST the same way per PHP
docs but weren't run.

PR: https://github.com/simon-downes/spl/pull/3
CI: none (the repository has no CI checks)

## Follow-ups