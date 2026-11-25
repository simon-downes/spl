# 005 — DB DSN parsing, SQLite paths, typed binding and facade docblocks

Status: reviewing
Branch: build-it/005-db-dsn (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/005-db-dsn

## Objective

Database URLs with percent-encoded credentials (`mysql://u:p%2Fss@host/db`) fail to parse,
absolute SQLite paths are impossible, `:memory:` turns into a file path once `SPL_ROOT` is
defined, bools bind as strings, `getInfo()` never reports server info for non-SQLite drivers,
and IDEs/PHPStan can't see the `DB` facade's methods. When done, all of these work as
described in Requirements, each covered by a PHPUnit test against SQLite.

## Context

Scope set by the user (Phase 2 lane `db-dsn`; other agents own the rest of the library):
1. `Str::parseURL()` urldecodes the whole URL before `parse_url()`, so `%2F`/`%23` in a
   password make it return null. Parse first, then decode components.
2. Support absolute SQLite paths (`sqlite:///abs/path.db`), keep `sqlite://localhost/rel.db`
   relative to `SPL_ROOT`, never prefix `:memory:`.
3. `Connection::bindParams()` binds bools as `PDO::PARAM_BOOL`, nulls as `PDO::PARAM_NULL`.
4. `@method static` docblocks on `src/DB.php` for every forwarded public `Connection` method,
   like `src/Log.php`.
5. `Connection::getInfo()`: `if (!$this->dsn->type == static::TYPE_SQLITE)` is always false;
   fix to `!==`.

Not in scope: insert/update/delete/transaction helpers, the prepared-statement cache, other
lanes' code. Another agent edits `Str::uncamelise()`/`xssClean()` in `src/Str.php`; only
`parseURL`/`buildURL` are touched here.

Codebase facts:
- The only caller of `Str::parseURL()` is `Connection::makeDSN()` (src/database/Connection.php:96);
  `Str::buildURL()` has no callers in src/ or tests/.
- `parse_url('sqlite:///var/data/app.db')` returns false; `parse_url('sqlite:/var/data/app.db')`
  returns scheme + path `/var/data/app.db` with no host (checked with PHP 8.4.28).
- `parse_url()` leaves `user`, `pass`, `host` and `path` percent-encoded; `parse_str()` decodes
  the query.
- `makeDSN()` is protected and the constructor connects immediately; `$pdo` is `final protected`
  (not readonly), so a test subclass can replace it.
- Tests must not define `SPL_ROOT` in the main process; use `#[RunInSeparateProcess]`.

## Baseline

- Check commands: `composer cs-check`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`,
  `composer test` (`composer check` runs all three)
- Setup: `composer install`
- Setup leftovers: none (vendor/ is gitignored)
- Failing before this work: none (cs clean, PHPStan 0 errors, 60 tests pass)
- Not included: `prompts/` (untracked in the user's checkout, unrelated)

## Decisions

| #   | Decision | Chosen | Rejected | Why | Source |
|-----|----------|--------|----------|-----|--------|
| D1  | How parseURL handles encoded characters | `parse_url()` the raw URL, then decode components | urldecode before parsing (today) | Decoding first turns `%2F`/`%23`/`%40` into URL delimiters, so `parse_url()` fails or mis-splits | user |
| D2  | Which components are decoded, and how | `rawurldecode` on `user`, `pass`, `host`, `path`; `query` decoded once by `parse_str()`; defaults are not decoded | decode only user/pass; `urldecode` | Today every component is decoded, so path/host decoding keeps encoded DB names/SQLite paths (e.g. `%20`) working; `rawurldecode` keeps a literal `+` in a password instead of turning it into a space | self |
| D3  | Accepted SQLite forms | `sqlite:///abs/path.db` = absolute; `sqlite://<host>/rel/path.db` = relative to `SPL_ROOT` (or cwd if undefined); `:memory:` in either form is never prefixed | keep relative-only | User-chosen; existing `sqlite://localhost/:memory:` tests keep passing | user |
| D4  | Where the empty-authority form (`scheme:///path`) is handled | In `Str::parseURL()`: rewrite a leading `scheme:///` to `scheme:/` before `parse_url()`, giving `host` = default and an absolute `path` | special-case SQLite in `makeDSN()` before calling parseURL | `parseURL()` is the single URL parser here and `makeDSN()` consumes its parts; RFC 3986 allows an empty authority. Side effect: `x:///foo` parses (host `''`) instead of returning null | self |
| D5  | How makeDSN tells absolute from relative | Empty host after parsing = absolute; any non-empty host = relative (host otherwise ignored and forced to `localhost`, as today) | accept only `localhost` as the relative marker and reject other hosts | Rejecting other hosts would break existing DSNs with no user ask | self |
| D6  | How `:memory:` is detected | The path with surrounding slashes trimmed equals `:memory:` | match only `sqlite://localhost/:memory:` | `sqlite:///:memory:` would otherwise open a file literally named `/:memory:` | self |
| D7  | Parameter types in bindParams | `null` → `PARAM_NULL`, `bool` → `PARAM_BOOL`, `int` → `PARAM_INT`, everything else `PARAM_STR` | bools as strings (today) | User-chosen; today `false` binds as `''` | user |
| D8  | buildURL with parseURL output | Skip components that are `null` or `''`; `rawurlencode` user and (when shown) pass | leave buildURL alone; also encode path/query | parseURL always fills missing parts with `''`, so today `buildURL(parseURL('mysql://h/db'))` gives `mysql://:<password>@h:/db`; now parseURL decodes credentials, buildURL must re-encode them to round-trip. Path encoding needs per-segment handling and no caller needs it | self |
| D9  | getInfo fix | `!==` comparison | — | User-chosen | user |
| D10 | Test seam for non-SQLite DSNs and getInfo's server-info branch | Anonymous `Connection` subclasses in the test: one overrides the constructor to expose `makeDSN()`; one replaces `$pdo` with a `PDO` subclass returning a fixed `ATTR_SERVER_INFO` and sets the DSN type to `mysql` | install MariaDB/Postgres in the container | The repo's tests run against SQLite only; a real server can't be assumed | self |
| D11 | Guard against DB docblock drift | Reflection test: the `@method static` tags on `DB` list exactly `Connection`'s public non-static methods (minus `__construct`), each with the same parameter names in order | no test | Forwarded methods are added to `Connection` over time; a missing tag fails silently in IDEs/PHPStan | self |

## Requirements

- MUST parse percent-encoded credentials: `Str::parseURL('mysql://u%40x:p%2Fs%23s%2B@host/db')`
  returns `user` `u@x`, `pass` `p/s#s+`, `host` `host`, `path` `/db`.
  - AC: StrTest data-provider cases for `%2F`, `%23`, `%40`, `+` in the password and an encoded user pass.
  - AC: `Connection::makeDSN('mysql://u:p%2Fss@host/db')` gives `user` `u`, `pass` `p/ss`, `pdo`
    `mysql:host=host;port=3306;dbname=db;charset=utf8mb4`.
- MUST keep parseURL's return shape: keys `scheme, host, port, user, pass, path, query`, missing
  parts from `$defaults` or `''`, `query` an array, `port` an int when present, null when
  `parse_url()` fails.
  - AC: StrTest asserts the full array for a plain URL, defaults, a query (`?a=1&b=x%26y` →
    `['a' => '1', 'b' => 'x&y']`) and null for `http://:80`.
- MUST decode `host` and `path` once (`/my%20db` → `/my db`).
  - AC: StrTest case.
- MUST open an absolute SQLite path from `sqlite:///abs/path.db`, unprefixed even when `SPL_ROOT` is defined.
  - AC: ConnectionTest writes a row through `'sqlite://' . $tempfile` (an absolute path, so the URL starts `sqlite:///`), reads it back through a second Connection, and `getDSN()->pdo === 'sqlite:<tempfile>'`.
  - AC: separate-process test with `SPL_ROOT` defined gives the same unprefixed `pdo`.
- MUST keep `sqlite://localhost/rel/path.db` relative to `SPL_ROOT` when defined.
  - AC: separate-process test defines `SPL_ROOT` as a temp dir; the database file is created at `SPL_ROOT/rel.db`.
- MUST never prefix `:memory:`: `sqlite://localhost/:memory:` and `sqlite:///:memory:` give `pdo` `sqlite::memory:` with or without `SPL_ROOT`.
  - AC: separate-process test with `SPL_ROOT` defined (fails before the fix); main-process test for both forms.
- MUST reject a SQLite URL with no database (`sqlite:///`, `sqlite://localhost/`) with `RuntimeException` "No database name specified".
  - AC: ConnectionTest.
- MUST bind `null` as `PDO::PARAM_NULL` and bools as `PDO::PARAM_BOOL`.
  - AC: against SQLite, `getOne('SELECT typeof(?)', [false])` is `'integer'` (today `'text'`), `[true]` → `'integer'`, `[null]` → `'null'`, `[5]` → `'integer'`, `['5']` → `'text'`; a named `:flag` bool parameter matches an `INTEGER` column holding 0.
- MUST append `ATTR_SERVER_INFO` to `getInfo()['server']` for non-SQLite drivers and not for SQLite.
  - AC: SQLite `getInfo()` returns `driver` `sqlite`, `server` equal to `ATTR_SERVER_VERSION`, plus `dsn`, `stats`, `client`.
  - AC: test subclass with type `mysql` and a PDO stub gives `server` `<version>: <info>` (fails before the fix).
- MUST document every forwarded public `Connection` method as `@method static` on `DB`, with matching parameters and return types.
  - AC: reflection test (D11) passes.
  - AC: a throwaway PHPStan run on a file calling `DB::getAll()`/`DB::getOne()`/`DB::insertId()` reports their declared return types and no errors (not committed).
- MUST round-trip `Str::buildURL(Str::parseURL($url), true)` for `scheme://user:pass@host:port/path` URLs with encoded credentials, and mask the password by default.
  - AC: StrTest cases, including `mysql://h/db` → `mysql://h/db` and `sqlite:///var/x.db` → `sqlite:///var/x.db`.
- MUST pass `composer check` with no new failures; no PHPStan ignores or baseline edits.
  - AC: `composer check` exits 0.

Out of scope: insert/update/delete/transaction helpers, the prepared-statement cache, query
string output in `buildURL()`, SQLite host validation, `Str::uncamelise()`/`xssClean()`,
README/AGENTS docs (Phase 3).

## Design

### Overview

Fix the parser at its root (`Str::parseURL()` parses before decoding and accepts an empty
authority), then teach `Connection::makeDSN()` the three SQLite forms off the parsed host/path.
The binding, `getInfo()` and docblock fixes are independent one-liners with tests.

### Code structure

- `src/Str.php`: `parseURL()` (rewrite `^scheme:///` → `scheme:/`, `parse_url()` raw, rawurldecode
  user/pass/host/path), `buildURL()` (skip empty, encode user/pass). Docblocks state the
  decoding rules.
- `src/database/Connection.php`: `makeDSN()` SQLite branch; `bindParams()`; `getInfo()`;
  constructor docblock lists the accepted URL forms.
- `src/DB.php`: class docblock `@method static` lines, following `src/Log.php` lines 17-21.
- Tests: `tests/StrTest.php` (parseURL/buildURL providers), `tests/database/ConnectionTest.php`
  (DSN, SQLite paths, binding, getInfo), new `tests/DBTest.php` (docblock drift).

### Error handling

- Unparseable URL → parseURL returns null → makeDSN throws `RuntimeException` "Invalid database
  configuration: …" (unchanged). The message echoes the URL including any password (existing
  behaviour, out of scope; noted in Risks).
- Empty database name → `RuntimeException` "No database name specified" (unchanged).
- SQLite file that can't be opened → `PDOException` from `new PDO()` (unchanged).

## Milestones

### [x] M1 — Encoded credentials parse end to end

- **Approach:** In `Str::parseURL()` call `parse_url($url)` on the raw string, then
  `rawurldecode()` the parsed `user`, `pass`, `host`, `path` (not defaults); keep the loop and
  `parse_str()` for query. Update `buildURL()` per D8. Write the failing StrTest cases first.
  Add a `makeDSN` seam test (D10) in ConnectionTest for the MySQL URL.
- **Test seam:** `Str::parseURL()`, `Str::buildURL()`, `makeDSN()` via a test subclass.
- **Edge cases:** `+` in password → literal `+`; `%26` in a query value → `&` inside the value,
  not a separator; no user → `''`.
- **Deliverable:** `mysql://u:p%2Fss@host/db` yields password `p/ss` in the DSN.
- **Verify:** `vendor/bin/phpunit --filter 'StrTest|ConnectionTest'` passes; new cases failed before the change.
- **Outcome:** parseURL parses the raw URL and rawurldecodes user/pass/host/path; buildURL skips
  empty parts and re-encodes credentials. Before the change 10 of the new tests failed (encoded
  slash/hash/plus credentials, round trips, mask, makeDSN); after, `phpunit --filter
  'StrTest|ConnectionTest'` OK (46 tests), PHPStan 0 errors.

### [x] M2 — SQLite absolute paths and `:memory:`

- **Approach:** In `parseURL()` rewrite a leading `scheme:///` to `scheme:/` (D4). In
  `makeDSN()`'s SQLite branch: `:memory:` when the trimmed path is `:memory:`; absolute when
  host is `''` (db = path as parsed); otherwise relative (db = trimmed path, prefixed with
  `SPL_ROOT/` when defined). Keep the empty-db check on the trimmed path. Separate-process tests
  use `#[RunInSeparateProcess]` and define `SPL_ROOT` inside the test body; temp files/dirs are
  removed in the test or tearDown. ⚠️ The `:memory:` + `SPL_ROOT` test points `SPL_ROOT` at a
  directory that doesn't exist, so the pre-fix code fails with a `PDOException` instead of
  creating a stray `:memory:` file.
- **Test seam:** `new Connection($url)`, `getDSN()`, `Str::parseURL()`.
- **Edge cases:** `sqlite:///` → "No database name specified"; `sqlite:///tmp/my%20db.db` → `/tmp/my db.db`.
- **Deliverable:** `sqlite:///var/data/app.db` opens that file; `:memory:` stays in memory with `SPL_ROOT` defined.
- **Verify:** `vendor/bin/phpunit --filter ConnectionTest` passes; the `SPL_ROOT` + `:memory:` test failed before the change.
- **Outcome:** parseURL rewrites `scheme:///` to `scheme:/`; makeDSN's SQLite branch picks
  `:memory:` / absolute (empty host, `db` = absolute path) / relative (prefixed with `SPL_ROOT`).
  Before the change 6 new ConnectionTest cases failed (both absolute-path tests, decoded path,
  `sqlite:///:memory:`, `sqlite:///` rejection, and `:memory:` + `SPL_ROOT` with `PDOException`
  "unable to open database file") and 2 StrTest cases (empty authority, its round trip); the
  relative + `SPL_ROOT` test passed before and after (existing behaviour). After: full suite OK
  (85 tests, then StrTest 38 OK), PHPStan 0 errors, cs-check clean, no `spl-*` files left in /tmp.

### [x] M3 — Typed bool and null binding

- **Approach:** In `bindParams()` choose the type with a `match (true)` on `null`/`bool`/`int`.
  Failing `typeof(?)` tests first.
- **Test seam:** `Connection::getOne()` / `getRow()` against SQLite.
- **Deliverable:** `false` binds as integer 0.
- **Verify:** `vendor/bin/phpunit --filter ConnectionTest` passes; the bool cases failed before.
- **Outcome:** `match (true)` picks NULL/BOOL/INT/STR. Before: `typeof(?)` for `false`/`true` was
  `text` and the flags table test failed (3 failures); after: `phpunit --filter ConnectionTest` OK
  (26 tests), PHPStan 0 errors, cs-check clean.

### [x] M4 — getInfo reports server info for non-SQLite drivers

- **Approach:** `!==` fix. Tests per D10; the stub test failed before.
- **Test seam:** `Connection::getInfo()`.
- **Deliverable:** `getInfo()['server']` includes server info on non-SQLite connections.
- **Verify:** `vendor/bin/phpunit --filter ConnectionTest` passes.
- **Outcome:** `!==` fix. Before: the stub test got `'3.46.1'` instead of `'3.46.1: Uptime: 42'`;
  after: `phpunit --filter ConnectionTest` OK (28 tests), PHPStan 0 errors, cs-check clean.

### [x] M5 — DB facade `@method static` docblocks

- **Approach:** One `@method static <return> <name>(<params>)` line per forwarded method, types
  copied from `Connection` (array shapes from its `@return` tags where PHPStan accepts them).
  New `tests/DBTest.php` per D11. Throwaway PHPStan check per the AC, file under `/tmp`.
- **Test seam:** `ReflectionClass(DB::class)->getDocComment()`, PHPStan.
- **Deliverable:** IDEs and PHPStan resolve `DB::getAll()` etc.
- **Verify:** `vendor/bin/phpunit --filter DBTest` passes; PHPStan on the throwaway file reports the documented return types.
- **Outcome:** 22 `@method static` tags on `DB` (all public `Connection` instance methods), plus a
  separate-process facade test via `$_ENV['DB_DSN']`. The drift test failed against the old
  `DB.php`, passes now. Throwaway PHPStan run on `/tmp/db-dsn-phpstan/check.php` dumped
  `array<int, array<string, mixed>>` for `getAll`, `string` for `insertId`/`quote`, the `getInfo`
  shape, and flagged `DB::getRow(123)` (expects `PDOStatement|string`); file removed. PHPStan then
  flagged the bare `array` on `getLastError` (missingType.iterableValue), so both `DB` and
  `Connection::getLastError()` now say `array<int, mixed>`. `composer check`: cs clean, PHPStan 0
  errors, 98 tests OK.

## Deviations

## Risks

- "Invalid database configuration: {$url}" includes the password in the exception message
  (pre-existing; out of scope).
- PDO DSN strings are built with `sprintf` from decoded host/db; a `;` in a decoded value could
  inject DSN options. DSNs come from trusted config (`DB_DSN`); pre-existing behaviour.
- MySQL/Postgres/SQL Server DSN paths are only checked through `makeDSN()`; no live server.
- `PDO::PARAM_BOOL` is only verified on SQLite. pdo_pgsql sends bools as `t`/`f`, so a PHP bool
  bound to a Postgres INTEGER column now fails where `true` (`'1'`) used to work; BOOLEAN columns
  now work where `false` (`''`) used to fail. pdo_mysql's default emulated prepares inline `0`/`1`.
  From driver knowledge, not tested here.
- Consumers running PHPStan now get typed `DB::` calls, which can surface new errors in their code
  (e.g. passing an int as the statement). Intended.

## Review

- Plan review: 1 pass, self-conducted (no subagent tool in this session) against
  references/PLAN-REVIEW.md. Fixed: ⚠️ M2 pre-fix `:memory:` test could create a stray file
  (now uses a nonexistent `SPL_ROOT`); ℹ️ absolute-path AC didn't say the URL form; ℹ️ D11
  checked names only, not "matching parameters" (now compares parameter names). Verified codebase
  claims: `parse_url('http://:80')` is false, `file:///` already parses, only `makeDSN()` calls
  `parseURL()`, `src/Log.php:17-21` holds the `@method static` pattern. No ❌, so no second pass.

- Code review: self-conducted (no subagent tool in this session), roles `general` and `risk`
  (`risk` because the diff changes public library APIs, URL parsing of config input and SQL
  parameter binding), 1 round against references/CODE-REVIEW.md. Fixed: ℹ️ SQLite branch comment
  in `makeDSN()` didn't mention `:memory:`; ⚠️ (risk) Postgres `PARAM_BOOL` behaviour change was
  unrecorded, now under Risks and the PR's Breaking changes. Considered and rejected: DSN option
  injection via a decoded `;` in host/db (pre-existing: the old code decoded before parsing, so
  `%3B` already reached the DSN; config is trusted); password echoed in "Invalid database
  configuration" (pre-existing, out of scope, in Risks); `sqlite:////x` parsing as host `x`
  (unrealistic input). Spec check: every MUST traced to a passing test (StrTest parseURL/buildURL
  cases, ConnectionTest DSN/SQLite/binding/getInfo cases, DBTest) plus the throwaway PHPStan run;
  the `Connection::getLastError()` docblock change serves the docblock MUST (PHPStan rejected a
  bare `array` in `@method`).
- Merge risk: Medium: bool binding changes the SQL sent for every bool parameter on every driver,
  and only SQLite is tested here; limited because bools were mis-bound before (`false` as `''`)
  and the change is a one-line revert.

## Outcome

PR: pending
CI: pending

## Follow-ups
