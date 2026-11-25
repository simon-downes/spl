# 006 — Harden the HTTP client

Status: planning
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
