# 005 — DB DSN parsing, SQLite paths, typed binding and facade docblocks

Status: planning
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
