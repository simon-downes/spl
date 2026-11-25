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
