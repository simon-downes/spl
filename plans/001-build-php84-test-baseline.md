# 001 — PHP 8.4 floor, stable tooling and a minimal PHPUnit baseline

Status: planning
Branch: build-it/001-php84-test-baseline (base: master @ 98cb6cf)
Worktree: /workspace/.git/build-it-worktrees/001-php84-test-baseline

## Objective

SPL targets PHP `~8.1`, pulls dev-branch PHPStan builds, triggers PHP 8.4+ deprecations and has
no tests. When done, the library requires PHP `^8.4`, resolves only stable dev tooling, has no
known 8.4–8.6 deprecations, passes PHPStan with zero errors outside the baseline, and ships a
small, fast PHPUnit 12 suite wired into `composer test` / `composer check`. This is the setup PR
that later parallel PRs branch from.

## Context

The user wants a small, easy-to-review setup PR. Scope is fixed by the user (see Decisions,
`user` rows): PHP floor, stable tooling, four deprecation fixes, the 7 PHPStan errors, a fresh
minimal PHPUnit suite, and `test`/`check` composer scripts. Router, Request/Response fixes,
error handlers, DB DSN/binding, Http, CLI behaviour, Env/Str/bootstrap fixes and docs are later
PRs.

Codebase facts:
- `composer.json` has `"minimum-stability": "dev"`, `php ~8.1`, PHPStan `2.1.x-dev`, rules
  `2.0.x-dev`, php-cs-fixer `^3.40` (locked 3.81.0), twig `^3.0` (locked `3.x-dev`).
- Autoload `spl\` => `src/` plus `files: src/bootstrap.php`, which defines `SPL_CLI`,
  `SPL_REQUEST_ID`, `SPL_START_TIME` and `d()/dd()/env()`. `SPL_ROOT`/`SPL_DEBUG` are only
  defined by `SPL::init()`.
- `database\Connection::makeDSN()` prefixes SQLite paths with `SPL_ROOT . '/'` when defined, so
  `sqlite://localhost/:memory:` only stays in-memory if tests never define `SPL_ROOT`.
- `phpstan.neon`: level 8, strict rules, deprecation rules, `paths: [src]`, includes
  `phpstan-baseline.neon`, `reportUnmatchedIgnoredErrors: false`.
- `.php-cs-fixer.dist.php`: PER-CS2.0 with same-line braces; finder is `src/` only.
- An unmerged `simon/tests` branch has a PHPUnit 10 suite under `tests/Unit/`; not used.

## Baseline

- Check commands: `composer cs-check`; `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`;
  `composer test` (added by this build).
- Setup: `composer install` in the worktree. No untracked config needed.
- Setup leftovers: `vendor/`, `.php-cs-fixer.cache` (both gitignored).
- Failing before this work:
  - `composer cs-check`: 1 file (`src/CLI.php`, whitespace/spacing only, 64 changed lines).
  - PHPStan: 7 errors — CLI.php:189, 193 (return.type), 214, 263 (argument.type), 271
    (function.strict); database/Connection.php:171 (unset.possiblyHookedProperty);
    util/Debug.php:212 (constant.deprecated E_STRICT).
  - Tests: none exist.
- Not included: `prompts/` (untracked in the user's checkout, unrelated).

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | PHP floor | `"php": "^8.4"` | keep `~8.1`; `>=8.4` | Later PRs use 8.4 features; caret blocks an untested PHP 9 | user |
| D2 | Dev tooling stability | stable caret constraints (`phpstan/phpstan ^2.1`, `phpstan-strict-rules ^2.0`, `phpstan-deprecation-rules ^2.0`), drop `minimum-stability` | keep `x-dev` branches | Reproducible checks across parallel PRs | user |
| D3 | Runtime deps | keep `twig/twig ^3.0` (now resolves a stable 3.x) | drop or bump Twig | `SPL::render()` uses it; out of scope | user |
| D4 | php-cs-fixer constraint | `^3.95` (the stable release `composer update` resolves) | leave `^3.40` | 3.40 predates PHP 8.4 support; the floor guarantees 8.4 syntax (e.g. `final` properties, see D8) parses for anyone running `composer install` with an older lock | self |
| D5 | Deprecation fixes | remove `E_STRICT` row, `curl_close()`, `setAccessible()`; `is_integer` → `is_int` | version-guarded code | Floor is 8.4, so all four are no-ops or removals there | user |
| D6 | `processTokens()` null return | cast with `(string)` | `?? $text`; throw | `Str.php` already wraps every `preg_replace` as `(string) preg_replace(...)`; null only on PCRE error | self |
| D7 | `fgets(STDIN)` returning false (EOF) in `prompt()`/`select()` | new `protected static readLine(): string` that throws `RuntimeException('Unexpected end of input')` on false | `(string) fgets()` cast | Today EOF throws a `TypeError` from `trim(false)`; the cast would turn `select()` without a default into an infinite "Invalid choice" loop. Throwing keeps EOF fatal (later PR reworks it) | self |
| D8 | Connection `unset($this->pdo)` hooks error | declare the property `final protected PDO $pdo` (PHP 8.4 final property) | make class final; make property private/nullable; remove the `unset` | Keeps subclass read/write access and the destroy-before-reconnect order the `reconnect()` docblock relies on; only forbids redeclaring `$pdo` in a subclass | self |
| D9 | `in_array()` strict flag in `select()` | `in_array($input, array_map('strval', $keys), true)` | `in_array(..., true)` on raw keys | Int keys (list options) must still match string input such as `"1"` | user |
| D10 | Baseline | do not regenerate `phpstan-baseline.neon` | regenerate | A later docs PR regenerates it; unmatched entries are allowed | user |
| D11 | Test framework | PHPUnit `^12` in require-dev, `autoload-dev` `spl\tests\` => `tests/` | base on `simon/tests` (PHPUnit 10) | User wants a fresh minimal start | user |
| D12 | Test layout | `tests/` mirrors `src/` (`tests/util/ConfigTest.php` → `spl\tests\util\ConfigTest`), `final` classes extending `PHPUnit\Framework\TestCase` directly, `test*` method names, `#[DataProvider]` for tables | `tests/Unit/` subtree; a shared base class | Matches the PSR-4 map the user set and keeps each test self-contained; no shared state needs a base class yet | self |
| D13 | Test bootstrap | `bootstrap="vendor/autoload.php"`, no `tests/bootstrap.php`; tests never call `SPL::init()` or define `SPL_ROOT`/`SPL_DEBUG` | a bootstrap that defines `SPL_ROOT` | Autoload already loads `src/bootstrap.php`; defining `SPL_ROOT` would turn `sqlite://localhost/:memory:` into a file path (`Connection.php:145`) and constants can't be undefined per test | self |
| D14 | PHPUnit strictness | `failOnDeprecation`, `failOnNotice`, `failOnWarning`, `failOnRisky` on; `<source>` = `src/` | defaults | The point of this PR is to stop 8.4 deprecations; a test touching deprecated code must fail | self |
| D15 | `composer check` | `["@cs-check", "@phpstan", "@test"]` (Composer stops at the first failing entry) | a shell `&&` chain | Reuses existing scripts; works on every platform Composer runs on | user |
| D16 | Pre-existing cs-check failure in `src/CLI.php` | apply `php-cs-fixer fix src/CLI.php` as its own style-only commit before the logic change | leave failing | User requires cs-check to pass; a separate commit keeps the CLI logic diff readable | self |
| D17 | PHPStan scope | keep `paths: [src]` (tests not analysed) | add `tests/` | Analysing tests at level 8 + strict rules needs `phpstan/phpstan-phpunit` (new dependency) and is not requested | self |

## Requirements

- MUST require PHP `^8.4` and resolve only stable packages.
  - AC: `composer validate --strict` passes; `composer.json` has `"php": "^8.4"` and no
    `minimum-stability` key; `composer show` lists no `dev-`/`x-dev` versions.
- MUST keep `twig/twig ^3.0` as a runtime requirement.
  - AC: `composer show --no-dev` (`composer show -D`) includes `twig/twig` 3.x.
- MUST NOT use `E_STRICT`, `curl_close()`, `ReflectionProperty::setAccessible()` or the
  `is_integer` alias in `src/`.
  - AC: `grep -rnE 'E_STRICT|curl_close|setAccessible|is_integer' src` finds nothing.
  - AC: `tests/util/DebugTest.php` formats an `ErrorException` and an object with a private
    property under `failOnDeprecation` and passes (fails before the E_STRICT fix).
- MUST pass PHPStan (level 8, strict rules) with zero errors without new baseline entries or
  ignores.
  - AC: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` prints `[OK] No errors`;
    `git diff master -- phpstan.neon phpstan-baseline.neon` is empty; no new
    `@phpstan-ignore` in `src/`.
- MUST keep CLI behaviour except on PCRE failure and EOF (D6, D7, D9).
  - AC: covered by code review against D6/D7/D9 (CLI reads STDIN/posix TTY; no tests in this PR).
- MUST keep `Connection::reconnect()` working.
  - AC: a test creates a table on `sqlite://localhost/:memory:`, calls `reconnect()`, and sees
    an empty schema.
- MUST provide a PHPUnit 12 suite covering the happy paths of `util\Config`, `util\Env`, `Str`
  (slugify, pluralise/singularise, ordinal), `database\Connection` (execute, getRow, getOne,
  getCol, transactions), `web\Response` factories and `web\Request` construction.
  - AC: `composer test` passes with 0 failures, 0 deprecations, 0 warnings, in under 5 s.
  - AC: no test touches App/router, Request JSON with `; charset=`/empty JSON bodies, Response
    308/422/429 or invalid UTF-8, `Str::parseURL`, `Str::uncamelise`, or `APP_LOG_FILE`.
- MUST style-check `tests/`.
  - AC: `.php-cs-fixer.dist.php` finder includes `tests/`; `composer cs-check` passes.
- MUST add composer scripts `test` (`phpunit`) and `check` (cs-check, phpstan, test; fail fast)
  and keep the existing scripts.
  - AC: `composer check` exits 0; with a deliberate cs violation it exits non-zero before
    running PHPStan.
- MUST ignore `.phpunit.cache`.
  - AC: after `composer test`, `git status --short` shows no `.phpunit.cache`.

Out of scope: router/App, Request/Response fixes, error handlers, DB DSN/binding, Http client,
CLI behaviour, Env/Str/bootstrap fixes, README/AGENTS.md/CONTRIBUTING, regenerating the
PHPStan baseline, analysing tests with PHPStan.

## Design

### Overview

Update `composer.json` (+ lock) to PHP 8.4 and stable tooling with PHPUnit 12, make the
smallest source edits that remove the deprecations and the 7 PHPStan errors, and add one small
test class per covered `src/` class, mirrored under `tests/`.

### Stack

- New (dev): `phpunit/phpunit ^12` — user choice; 12 is the line supporting PHP 8.3+.
- Reused (dev), re-pinned: PHPStan 2 + strict/deprecation rules, php-cs-fixer 3.
- Reused (runtime): `twig/twig ^3.0`, ext-pdo, ext-mbstring. Tests need `pdo_sqlite`
  (installed in this container).

### Code structure

- `composer.json`, `composer.lock`, `.gitignore`, `.php-cs-fixer.dist.php` (finder adds
  `tests/`), new `phpunit.xml.dist`.
- `src/util/Debug.php`, `src/Http.php`, `src/CLI.php`, `src/database/Connection.php`: edits per
  D5–D9.
- New tests: `tests/util/ConfigTest.php`, `tests/util/EnvTest.php`, `tests/util/DebugTest.php`,
  `tests/StrTest.php`, `tests/database/ConnectionTest.php`, `tests/web/RequestTest.php`,
  `tests/web/ResponseTest.php`. Each file starts `<?php declare(strict_types=1);`.

### Non-functional

- Suite runtime: under 5 s; no network, no files outside `sys_get_temp_dir()`; Env tests use
  `SPL_TEST_*` variable names and remove them from `$_ENV` in `tearDown()`.

## Milestones

### [ ] M1 — Stable PHP 8.4 tooling with a running PHPUnit suite

- **Approach:** Edit `composer.json` per D1–D4, D11, D15 (`require-dev` adds `phpunit/phpunit
  ^12`; `autoload-dev`; scripts `test`, `check`). Run `composer update`, then tighten the
  php-cs-fixer floor to the resolved minor. Add `phpunit.xml.dist` per D13/D14, `.phpunit.cache`
  to `.gitignore`, `tests/` to the cs-fixer finder. Separate style-only commit: `php-cs-fixer fix
  src/CLI.php` (D16). Tracer test: `tests/util/ConfigTest.php` (dot-notation `get`, default,
  `has`, ArrayAccess read, immutability `LogicException`). ⚠️ PHPStan 2.x stable may be newer
  than the locked dev build and report new errors; if so they join M2.
- **Test seam:** `util\Config` public API.
- **Deliverable:** `composer test` runs PHPUnit 12 and passes; tooling is stable.
- **Verify:** `composer validate --strict` OK; `composer show | grep -E 'dev-|x-dev'` empty;
  `composer test` OK; `composer cs-check` exit 0.
- **Outcome:**

### [ ] M2 — Deprecation and PHPStan fixes

- **Approach:** First add `tests/util/DebugTest.php` (format `ErrorException` with
  `E_WARNING` → contains `WARNING`; `toString(42)` → `int(42)`; object with a private property
  shows its value) and see the ErrorException test fail on the E_STRICT deprecation. Then apply
  D5 (Debug.php lines 57, 212, 328; Http.php line 100), D6 (CLI.php `processTokens`), D7 (new
  `readLine()` used by `prompt()`/`select()`), D9, D8 (Connection.php line 41). Add
  `tests/database/ConnectionTest.php` with the reconnect test (in-memory SQLite).
- **Test seam:** `util\Debug` public methods; `database\Connection` public API.
- **Deliverable:** PHPStan reports zero errors; deprecated calls gone.
- **Verify:** `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` → `[OK] No errors`;
  `grep -rnE 'E_STRICT|curl_close|setAccessible|is_integer' src` empty; `composer test` OK.
- **Outcome:**

### [ ] M3 — Remaining minimal happy-path tests

- **Approach:** Add `EnvTest` (safeLoad of a temp file: plain, `true`/`false`/`null`, quoted,
  `${VAR}` interpolation, comment skipped, existing value not overwritten, missing → default),
  `StrTest` (slugify, pluralise/singularise incl. irregular and uncountable, ordinal via data
  provider), extend `ConnectionTest` (rawExec DDL, execute row count, insertId, getRow, getOne,
  getCol, getAll, commit persists, rollback discards, inTransaction), `ResponseTest`
  (html/json/text status + content-type + body; redirect 302/301 + location + isRedirect),
  `RequestTest` (getters, header case-insensitivity and missing → '', lookup query → body →
  default, `application/json` body decoded, cookies). Avoid every area listed in the
  Requirements' exclusion AC.
- **Test seam:** public APIs of each class.
- **Deliverable:** the full minimal suite passes under `composer check`.
- **Verify:** `composer check` exits 0; PHPUnit summary shows 0 failures/deprecations/warnings
  and time < 5 s.
- **Outcome:**

## Deviations

## Risks

- Consumers on PHP 8.1–8.3 can no longer install new releases (intended; needs a major/minor
  version note when tagged).
- `final protected $pdo` breaks any downstream subclass that redeclares `$pdo` (unlikely).

## Review

- Plan review:
- Code review:
- Merge risk:

## Outcome

PR: pending
CI: pending

## Follow-ups
