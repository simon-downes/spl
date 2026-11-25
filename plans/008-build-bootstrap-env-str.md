# 008 — Bootstrap, Env parser, Str and Debug fixes

Status: done
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

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | How is `SPL_CLI` determined? | `in_array(PHP_SAPI, ['cli', 'phpdbg'], true)` | STDIN resource check; `'cli'` only | STDIN can be closed or redirected in a real CLI run (reproduced). phpdbg runs scripts from a terminal with CLI semantics (no headers), which is what `SPL::run()`/`dd()` use the flag for; `cli-server` stays non-CLI because it serves HTTP | user (phpdbg: self) |
| D2 | Twig template directory key | `twig.template_path`, default `SPL_ROOT . '/templates'`, no fallback to `twig.templates` | keep `twig.templates`; read both | the documented example config uses `twig.template_path` | user |
| D3 | Env file syntax to add | optional `export ` prefix; inline `#` comments after unquoted values; matching surrounding `'`/`"` stripped at load time; lines without `=` skipped | status quo | user-reported gaps; quotes stripped at `get()` leak into `${VAR}` copies | user |
| D4 | Does `Env::get()` keep its own quote stripping? | No: `get()` returns values verbatim apart from true/false/null conversion | keep stripping in `get()` | once the loader unquotes, `get()`-time stripping unquotes a second time (`FOO='"x"'` would come back as `x`), and values from the real environment were already unquoted by the shell or orchestrator that set them | self |
| D5 | `${VAR}` interpolation scope | unquoted and double-quoted values only; single-quoted values are literal | interpolate everywhere | dotenv/shell convention, and gives users a way to write a literal `${...}` | self |
| D6 | What starts an inline comment in an unquoted value? | a `#` preceded by whitespace, evaluated on the raw text after `=` (so `FOO= # note` is empty) | any `#` | keeps `COLOR=#fff` and `URL=http://host/#frag` intact, matching shell word rules | self |
| D7 | What counts as a quoted value? | trimmed value starts with `'` or `"` and has a matching closing quote followed only by optional whitespace and an optional `# comment`; inner text kept verbatim, no escape sequences | backslash escape support | the parser has never had escapes; one anchored regex keeps it small. A value with no closing quote, or text after it, is treated as unquoted (kept literally, quotes included) | self |
| D8 | Lines without `=` or with an empty key | skipped silently | throw; log | `safeLoad()` is best-effort by contract; `Log` belongs to another lane and depends on init state | self |
| D9 | `uncamelise()` output for non-alphanumerics | each run of characters that are not Unicode letters/digits (`[^\p{L}\p{N}]+`, `_` included) becomes one `_`; no trimming; `uncamelise('helloWorld Foo-Bar')` = `hello_world_foo_bar`. Also fix the first regex's `[A-Z+]` to `[A-Z]+` | ASCII `[^A-Za-z0-9]`; trimming leading/trailing `_` | the function already lower-cases with `mb_strtolower`, so ASCII-only would turn `café-Bar` into `caf__bar`; trimming would change `_private`-style input. `[A-Z+]` matched a literal `+` by accident | self |
| D10 | How does `getString()` detect ASCII? | `mb_check_encoding($str, 'ASCII')` first → no label; otherwise `mb_detect_encoding()` over UTF-8, Windows-1252, ISO-8859-1 (strict); `false` → `unknown` | reorder the candidate list | an explicit check doesn't depend on mbstring's candidate scoring (PHP 8.1+ scores candidates rather than taking the first match) | self |
| D11 | Test seam for `SPL_CLI` | spawn `PHP_BINARY` with `proc_open()` (array command, no shell) running code that closes STDIN, requires `vendor/autoload.php` and prints `var_export(SPL_CLI, true)` | new public helper as a seam; `#[RunInSeparateProcess]` | the bootstrap runs at autoload, before test code, so only a fresh process can close STDIN first; a helper would add public API just for tests | self |
| D12 | Where does the Twig test live? | new `tests/SPLRenderTest.php`, `#[RunInSeparateProcess]` | `tests/SPLTest.php` | the error-handling lane is likely to create `tests/SPLTest.php`; a separate file avoids a merge conflict | self |
| D13 | How is `xssClean()` removed? | deleted outright, no shim | deprecate first | user decided removal; pre-1.0 personal library; listed under Breaking changes | user |
| D14 | Quoted `"true"`/`"false"`/`"null"` in env files | converted by `get()` like unquoted ones (found during M1) | keep them as strings by tracking quotedness | `$_ENV` holds plain strings, so quotedness can't survive load-time unquoting without a side table; matches Laravel/phpdotenv `env()` behaviour | self |

## Requirements

- MUST define `SPL_CLI` as `true` under the `cli` and `phpdbg` SAPIs regardless of STDIN's state.
  - AC: a PHP subprocess that runs `fclose(STDIN)` before requiring the autoloader prints `true` (tests/BootstrapTest.php).
- MUST define `SPL_CLI` as `false` under any other SAPI.
  - AC: manual: `php -S 127.0.0.1:<port> <router>` whose router requires the autoloader and echoes `var_export(SPL_CLI, true)`; `curl` returns `false`.
- MUST make `SPL::render()` load templates from config `twig.template_path`, defaulting to `SPL_ROOT . '/templates'`, and ignore `twig.templates`.
  - AC: separate-process tests in tests/SPLRenderTest.php: (a) `template_path` set to a custom dir renders a template there; (b) no key renders from `<root>/templates`; (c) only `twig.templates` set to a dir holding the template throws `Twig\Error\LoaderError`.
- MUST accept an optional `export ` prefix in env files.
  - AC: `export SPL_TEST_EXPORTED=yes` → `Env::get('SPL_TEST_EXPORTED') === 'yes'`.
- MUST strip inline comments from unquoted values per D6 and keep `#` inside quoted values.
  - AC: `A=bar # c` → `bar`; `A=#fff` → `#fff`; `A=http://h/#f` → `http://h/#f`; `A="x # y" # c` → `x # y`; `A='x # y'` → `x # y`.
- MUST strip matching surrounding quotes at load time, so `$_ENV` and `${VAR}` copies hold the unquoted value.
  - AC: `Q="quoted value"`, `R=${Q}!` → `$_ENV['Q'] === 'quoted value'`, `Env::get('R') === 'quoted value!'`; `M="unterminated` → `"unterminated` (literal).
- MUST interpolate `${VAR}` in unquoted and double-quoted values but not in single-quoted values.
  - AC: `S='${SPL_TEST_STRING}'` → `${SPL_TEST_STRING}`; `D="${SPL_TEST_STRING} there"` → `hello there`.
- MUST skip lines without `=` without emitting a PHP warning.
  - AC: a file containing `NOT_AN_ASSIGNMENT` loads under PHPUnit's failOnWarning and the other values load.
- MUST NOT overwrite variables already set in `$_ENV` or the process environment, and MUST keep `Env::get()`'s true/false/null conversion.
  - AC: existing tests testLoadDoesNotOverwriteExistingValues and testGetConvertsTypedStrings pass.
- MUST return values from the real environment verbatim from `Env::get()` (no quote stripping, D4).
  - AC: `$_ENV['SPL_TEST_RAW'] = '"kept"'` → `Env::get('SPL_TEST_RAW') === '"kept"'`.
- MUST convert each run of non-letter/non-digit characters in `Str::uncamelise()` to `_` (D9).
  - AC: `helloWorld Foo-Bar` → `hello_world_foo_bar`; `XMLHttpRequest` → `xml_http_request`; `already_snake` → `already_snake`; `café-Bar` → `café_bar`.
- MUST remove `Str::xssClean()` and keep `stripControlChars()` and `normaliseLineEndings()`.
  - AC: `method_exists(Str::class, 'xssClean') === false`; `stripControlChars("a\x00b\tc")` → `"ab\tc"`; `normaliseLineEndings("a\r\nb\r\r\r\nc")` → `"a\nb\n\nc"`.
- MUST show no encoding label for pure-ASCII strings in `Debug::getString()`, and keep the label for other strings.
  - AC: `'abc'` → `string(3) "abc"`; `"caf\xc3\xa9"` → `string(5; UTF-8) "café"`; `"caf\xe9"` → starts `string(4; Windows-1252)`.

Out of scope: `SPL::init()/run()/error()`, Log, router, Request/Response, DB, Http, CLI, `Str::parseURL()/buildURL()`, docs (README/AGENTS/CONTRIBUTING), escape sequences and multi-line values in env files.

## Design

### Overview

Five independent fixes plus one removal, each confined to the function named in Context.
The only non-trivial logic is the env-line parser, rewritten inside `Env::safeLoad()` /
`Env::parseValue()` as: trim → skip blank/`#` lines → strip `export ` → skip if no `=` or
empty key → skip if already set → parse value (quoted regex per D7, else strip inline
comment per D6 and trim) → interpolate unless single-quoted.

### Code structure

- src/util/Env.php: `safeLoad()` handles line-level rules; `parseValue()` handles value rules and calls a new protected `interpolate()` for `${VAR}` (protected, not private, because the class calls its helpers through `static::`); `get()` loses the quote regex. Docblocks updated.
- src/bootstrap.php: one-line `SPL_CLI` change.
- src/SPL.php: `render()` config key and its docblock line only.
- src/Str.php: `uncamelise()` regexes; delete `xssClean()` and its docblock.
- src/util/Debug.php: `getString()`.
- Tests: tests/util/EnvTest.php (extend; follow its temp-file and `SPL_TEST_*` pattern), tests/BootstrapTest.php (new), tests/SPLRenderTest.php (new), tests/StrTest.php (extend; follow its DataProvider pattern), tests/util/DebugTest.php (extend).

### Error handling

- `${VAR}` referencing an undefined variable keeps throwing `RuntimeException` (existing behaviour).
- `proc_open()` failure in BootstrapTest fails the test with a message.

## Milestones

### [x] M1 — Env file parser handles export, comments, quotes and bad lines

- **Approach:** Write failing EnvTest cases first (one temp file per test via a small `writeEnv(array $lines)` helper over `$this->file`). Then rewrite the loop in `Env::safeLoad()` and `Env::parseValue()` per Design; drop the quote regex from `Env::get()`. ⚠️ strip comments before interpolation so an interpolated value containing ` #` isn't truncated. ⚠️ FILE_IGNORE_NEW_LINES leaves `\r` on CRLF lines; trimming covers it.
- **Test seam:** `Env::load()` / `Env::get()` / `$_ENV`.
- **Edge cases:** `FOO=` → `''`; `FOO= # note` → `''`; `export FOO` (no `=`) → skipped; `=bar` → skipped; `FOO="a"b` → `"a"b`; `FOO=""` → `''`.
- **Deliverable:** env files with the new syntax load as the Requirements state.
- **Verify:** `vendor/bin/phpunit tests/util/EnvTest.php` passes (new cases fail on master first), then `composer check` passes.
- **Outcome:** Added 9 EnvTest cases; before the fix 6 failed and 1 errored ("Undefined array key 1" at Env.php:123); CRLF passed already. `safeLoad()` now trims, skips blank/comment/no-`=`/empty-key lines and strips `export `; `parseValue()` handles quotes (D7) and inline comments (D6) and delegates `${VAR}` to a new protected `interpolate()` (skipped for single quotes); `get()` no longer strips quotes. `vendor/bin/phpunit tests/util/EnvTest.php`: OK (15 tests, 54 assertions); `composer check`: cs clean, PHPStan 0 errors, OK (68 tests). Deviation: see D14.

### [x] M2 — SPL_CLI from PHP_SAPI and Twig reads twig.template_path

- **Approach:** tests/BootstrapTest.php (new, `final class BootstrapTest`, namespace `spl\tests`): `proc_open([PHP_BINARY, '-r', $code], [1 => pipe, 2 => pipe], $pipes)` where `$code` closes STDIN, requires `dirname(__DIR__) . '/vendor/autoload.php'` and echoes `var_export(SPL_CLI, true)`; assert stdout `true`. tests/SPLRenderTest.php (new): three `#[RunInSeparateProcess]` tests that build a temp root (`sys_get_temp_dir()` + unique dir) with `config/config.php` and a template, call `SPL::init($root, false)` and `SPL::render()`; tearDown removes the dir. Then change bootstrap.php:12 and SPL.php:116 (+ docblock at :107).
- **Test seam:** the `SPL_CLI` constant in a fresh process; `SPL::render()`.
- **Deliverable:** CLI detection survives a closed STDIN; `twig.template_path` is honoured.
- **Verify:** `vendor/bin/phpunit tests/BootstrapTest.php tests/SPLRenderTest.php` passes (fails on master first); manual cli-server check prints `false`; `composer check` passes.
- **Outcome:** tests/BootstrapTest.php (2 tests) and tests/SPLRenderTest.php (3 separate-process tests) added; before the fix the closed-STDIN test got `'false'`, the template_path test raised `LoaderError: ".../templates" directory does not exist` and the legacy-key test didn't throw. After: `vendor/bin/phpunit tests/BootstrapTest.php tests/SPLRenderTest.php` OK (5 tests). Manual: `php -S 127.0.0.1:18765 router.php` + `curl` printed `false cli-server`. PHPStan then reported `if.alwaysTrue`/`booleanNot.alwaysFalse` at SPL.php:140/173 because it evaluated `PHP_SAPI` as `cli`; fixed by listing `SPL_CLI` under `dynamicConstantNames` in phpstan.neon (a config fact, not an ignore). `composer check`: OK (73 tests), PHPStan 0 errors.

### [x] M3 — Str::uncamelise fixed, xssClean removed, Debug ASCII label

- **Approach:** add `uncamelProvider` + `testUncamelise`, `testXssCleanRemoved`, `testStripControlChars`, `testNormaliseLineEndings` to tests/StrTest.php (after the existing tests, clear of any parseURL tests the db-dsn lane adds) and string-label tests to tests/util/DebugTest.php; see them fail; then fix `uncamelise()` (D9), delete `xssClean()`, and change `getString()` (D10).
- **Test seam:** `Str::uncamelise()`, `Str` class methods, `(new Debug())->toString()`.
- **Deliverable:** the Str and Debug ACs hold.
- **Verify:** `vendor/bin/phpunit tests/StrTest.php tests/util/DebugTest.php` passes; `composer check` passes.
- **Outcome:** Added `testUncamelise` (6 cases), `testXssCleanIsRemoved`, `testStripControlChars`, `testNormaliseLineEndings` and two Debug label tests. Before the fix 5 failed (e.g. `'hello_world foo-bar'`, `'string(3; UTF-8) "abc"'`, xssClean present). Separator regex is now `/[^\p{L}\p{N}]+/u`, first regex `([A-Z]+)`, `xssClean()` deleted (no other src/tests callers; AGENTS.md mentions it, left for the Phase 3 docs PR), `getString()` checks ASCII first. `vendor/bin/phpunit tests/StrTest.php tests/util/DebugTest.php` OK (35 tests); `composer check` OK (84 tests, 179 assertions), PHPStan 0 errors, cs clean.

## Deviations

- M2: phpstan.neon gains `dynamicConstantNames: [SPL_CLI]` (file not named in the plan) so PHPStan doesn't treat the SAPI-derived constant as always true. No behaviour change.
- M1: quoted `"true"`, `"false"` and `"null"` values in env files are now converted to bool/null by `Env::get()` (before, the quotes blocked conversion). Consequence of load-time unquoting (D3); recorded as D14 and as a breaking change.

## Risks

- Parallel lanes edit src/SPL.php and src/Str.php; hunks here are kept to the named functions so merges stay mechanical.
- tests/StrTest.php is also likely extended by the db-dsn lane; new tests are appended at the end of the class to keep conflicts trivial.
- tests/SPLRenderTest.php calls `SPL::init($root, false)`; if the error-handling lane changes `init()`'s signature or side effects (e.g. installing handlers), this test may need a trivial follow-up after merge.
- Camel-case splitting (`([a-z\d])([A-Z])`) stays ASCII-only, so `caféBar` becomes `cafébar`; out of scope (D9 covers only the separator regex).
- Non-CLI `SPL_CLI` is verified only via the built-in server SAPI, not FPM/Apache (same `PHP_SAPI` mechanism).

## Review

- Plan review: 1 pass, self-conducted (no subagent tool available in this session) against references/PLAN-REVIEW.md. ⚠️ M3/Requirements AC `caféBar → café_bar` was wrong (camel splitting is ASCII-only, so `éB` never splits) — changed to `café-Bar` and recorded the limitation under Risks. ℹ️ double-negative wording of the overwrite MUST — reworded. ℹ️ merge-conflict exposure in tests/StrTest.php and the SPLRenderTest dependency on `SPL::init()` — added to Risks. No ❌, so no second pass.
- Code review: 1 round, roles `general` and `risk` (risk applies: env-file input, public library APIs and a config key), both self-conducted against references/CODE-REVIEW.md because no subagent tool is available in this session. Findings: ℹ️ the moved undefined-`${VAR}` error path had no test — added `testLoadThrowsForUndefinedReference` (`composer check`: OK, 85 tests, 182 assertions; PHPStan 0 errors; cs clean). ℹ️ plan Code structure didn't mention the new `interpolate()` helper — updated. Risk pass: no shell/SQL/secrets; the quoted-value regex is lazy but anchored and runs once per env line (developer-controlled, short), no ReDoS concern; breaking changes (xssClean removal, `twig.templates` ignored, `Env::get()` no longer unquoting, quoted `"true"` converted) are listed in the PR. Considered and rejected: making `interpolate()` private (would break `static::` calls from subclasses).
- Merge risk: Medium: consumers relying on `twig.templates`, `Str::xssClean()` or `Env::get()` unquoting real-environment values break, but the first two fail loudly (Twig `LoaderError`, undefined-method error) and all are listed under Breaking changes; the parser changes are covered by 11 new EnvTest cases.

## Outcome

Delivered all six scoped fixes (M1-M3). Evidence: every new bug test failed on the base first (recorded per milestone), and the final `composer check` passes: php-cs-fixer clean, PHPStan 0 errors, PHPUnit OK (85 tests, 182 assertions; base had 60). Non-CLI `SPL_CLI` verified manually under the built-in server SAPI (`false cli-server`). Not verified: FPM/Apache/phpdbg SAPIs (no binaries in the container; same `PHP_SAPI` check).

PR: pending
CI: pending

## Follow-ups

- Phase 3 docs: AGENTS.md/README still list `Str::xssClean()` and describe env-file syntax and `Env::get()` quote stripping.
- `Str::uncamelise()` camel-case splitting is ASCII-only (`caféBar` → `cafébar`).
- Env files: no escape sequences or multi-line values.
