# 008 — Bootstrap, Env parser, Str and Debug fixes

Status: planning
Branch: build-it/008-bootstrap-env-str (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/008-bootstrap-env-str

## Objective

Fix a set of small correctness bugs in SPL's bootstrap and utility layer: CLI detection,
the Twig template-path config key, the `.env` file parser, `Str::uncamelise()` and
`Debug::getString()`'s encoding label, and remove the misleading `Str::xssClean()`. When
done each bug has a reproducing test that now passes, and `composer check` is green.

## Context

Phase 2 lane `bootstrap-env-str` of a parallel clean-up of the library (six other agents
own other files). User-settled scope:

1. `SPL_CLI` is defined from `defined('STDIN') && is_resource(STDIN) && get_resource_type(STDIN) == 'stream'`
   (src/bootstrap.php:12). Verified: `php -r 'fclose(STDIN); require "vendor/autoload.php"; var_export(SPL_CLI);'`
   prints `false` under the CLI SAPI. Switch to `PHP_SAPI`.
2. `SPL::render()` (src/SPL.php:116) reads `twig.templates`, but the documented config uses
   `twig.template_path`, which is silently ignored.
3. `Env::safeLoad()`/`parseValue()` (src/util/Env.php:98-164): no `export ` prefix, no inline
   comments, quotes stripped only at `Env::get()` time (so `${VAR}` copies the quotes), and a
   line without `=` triggers "Undefined array key 1". The `parseValue()` docblock already
   claims it removes quotes.
4. `Str::uncamelise()` (src/Str.php:190-202): third regex `'/^A-Z^a-z^0-9]+/'` is malformed;
   `uncamelise('helloWorld Foo-Bar')` returns `hello_world foo-bar`.
5. `Str::xssClean()` (src/Str.php:286-342) is a blacklist sanitiser; remove it, keep
   `stripControlChars()` and `normaliseLineEndings()`.
6. `Debug::getString()` (src/util/Debug.php:121-125) passes ASCII last to
   `mb_detect_encoding()`, so ASCII strings get `; UTF-8`. Verified on PHP 8.4.28:
   `mb_detect_encoding("abc", ["UTF-8","WINDOWS-1252","ISO-8859-1","ASCII"], true)` is `UTF-8`.

Constraints: only the config lookup in `SPL::render()` is mine in src/SPL.php (another agent
edits init/run/error); only uncamelise/xssClean in src/Str.php (another agent edits
parseURL/buildURL). Tests must not call `SPL::init()` in the main process. No docs updates
(README/AGENTS/CONTRIBUTING) — Phase 3 syncs docs. No PHPStan baseline changes.

## Baseline

- Check commands: `composer cs-check`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `composer test` (all via `composer check`)
- Setup: `composer install`
- Setup leftovers: none (vendor/ is gitignored)
- Failing before this work: none (60 tests pass, PHPStan 0 errors, cs clean)
- Not included: `prompts/` (untracked in /workspace, unrelated)
