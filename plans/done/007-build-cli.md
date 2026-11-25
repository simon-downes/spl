# 007 — CLI colours, end of input, exit codes and generated help

Status: done
Branch: build-it/007-cli (base: master @ fcd7a53)
Worktree: /workspace/.git/build-it-worktrees/007-cli

## Objective

Make `spl\CLI` behave correctly outside an interactive terminal and give command-line apps
proper exit codes and generated help. When done: colour detection needs no undeclared
extension and honours `NO_COLOR`; prompts never throw or loop forever on end of input;
command handlers set the process exit code; `config/commands.php` entries carry a
description that a built-in `help` command lists; errors go to STDERR.

## Context

Phase 2 of a library-wide clean-up (six other agents own other files; this build owns
`src/CLI.php` only). Scope was settled by the user up front (see Decisions, `user`
rows); no interview questions.

Codebase facts:
- `src/CLI.php` is a static utility class. `processTokens()` calls `posix_isatty(STDOUT)`
  (ext-posix is not declared in composer.json) and, with colours off, strips every
  `[lowercase_word]`, so `select()` option keys like `[r]` vanish when piped.
- Phase 1 added `readLine()`, which throws `RuntimeException('Unexpected end of input')`
  on EOF; `prompt()`, `confirm()` and `select()` all use it.
- `CLI::handle()` loads `SPL_ROOT . '/config/commands.php'` (name => callable), prints
  unknown-command errors to STDOUT and `exit(1)`s; handler return values are ignored.
  Its only caller is `SPL::run()` (src/SPL.php:141), which catches Throwables and passes
  them to `SPL::error()` (logs via `Log::error`, exits 1).
- No CLI tests exist. phpunit.xml.dist fails on stray output, notices and warnings.
  `variables_order` is `GPCS`, so real environment variables are only visible through
  `getenv()`.
- `proc_open()` with a `['pty']` descriptor works in this container, which lets a test
  run a child PHP process whose STDOUT is a terminal.

## Baseline

- Check commands: `composer cs-check`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `composer test` (`composer check` runs all three)
- Setup: `composer install`
- Setup leftovers: none (vendor/ and .phpunit.cache are gitignored; `git status` clean)
- Failing before this work: none (cs clean, PHPStan 0 errors, 60 tests pass)
- Not included: `/workspace/prompts/` (untracked, unrelated)

## Decisions

| #  | Decision | Chosen | Rejected | Why | Source |
|----|----------|--------|----------|-----|--------|
| D1 | TTY detection | `stream_isatty()` on the stream being written | `posix_isatty()` | Core PHP; ext-posix isn't declared in composer.json | user |
| D2 | Colour opt-out | Off when `NO_COLOR` is set to a non-empty value | Ignore it | https://no-color.org | user |
| D3 | How `NO_COLOR` is read | Raw `getenv('NO_COLOR')` | `env()` / `$_ENV` first | `env()` converts `false`/`null` strings, but no-color.org says any non-empty value disables colour; `NO_COLOR` is a user's shell preference, not app config, so .env (`$_ENV`) isn't consulted, which also keeps `putenv()` in tests authoritative | self |
| D4 | Token handling | Replace (colours on) or strip (colours off) only names in `$tokens`; leave every other `[...]` untouched in both modes | Strip any `[a-z_]+` | Phase 1 bug: `select()` keys like `[r]` vanished when piped | user |
| D5 | Data inside formatted output (select keys, prompt/select defaults, help command names, unknown command name) | Inserted literally, never token-processed; styled by wrapping in an ANSI code | Interpolate into the markup string | A select key or default named `red` or `bold` is a known token and would otherwise be replaced/stripped; user-typed command names shouldn't change styling | self |
| D6 | EOF in `prompt()` / `confirm()` | Return the default (`''` when `prompt()` has none) | Throw | User decision | user |
| D7 | EOF in `select()` | Return `$default` when non-null; otherwise throw `RuntimeException('Unexpected end of input')` | Return null; keep looping | User decision; every loop iteration reads a line, so EOF always ends the loop | user |
| D8 | Output after EOF | Print a newline (`PHP_EOL`) to STDOUT when EOF ends a prompt | Print nothing | The prompt was printed without a newline; without one, the next output (or the shell prompt) runs onto the prompt line | self |
| D9 | Invalid `select()` choice message | Stays on STDOUT | STDERR | It's part of the interactive dialogue next to the prompt, not a program error | self |
| D10 | Exit codes | `handle()` returns `never`: it calls `exit()` with the code `dispatch()` returns (see D11) | Return the code and have `SPL::run()` call `exit()` | Keeps the change inside CLI.php (SPL.php belongs to the error-handling PR); no SPL.php edit needed | self |
| D11 | Handler return value (revised after the independent review) | Used as the exit code only when the handler declares an `int` or `?int` (`int|null`) return type, checked with `ReflectionFunction` on `Closure::fromCallable($handler)` (covers closures, functions, `[obj, 'm']`, `'Class::m'` and invokable objects); null -> 0 and an int outside 0-255 throws `RuntimeException` naming the command. Handlers with no return type or any other type (`void`, `mixed`, `string`, unions...) exit 0 whatever they return | Honour any `?int` result and throw for other values (the original D11) | Arrow functions return their expression, so `fn() => file_put_contents(...)` exited with the byte count and `fn() => print(...)` with 1, with no message; `exit(256)` would exit 0, so out-of-range ints still throw | user |
| D12 | commands.php format | `name => ['description' => string, 'handler' => callable]` only; no BC | Also accept bare callables | User decision | user |
| D13 | Validation failures | Thrown as `RuntimeException` naming the command and the problem, before any command runs | Print to STDERR and exit | They are programming errors; `SPL::run()` already routes Throwables to `SPL::error()` (log + exit 1) | self |
| D14 | Other entry checks | Also reject a non-array entry, an empty name and an integer key (the message explains that PHP turns numeric names such as `'2024'` into integer keys, as it does for an entry with no name); extra keys are ignored | Reject unknown keys | Same failure mode as a bad handler; ignoring extras keeps the format open (a typo in a required key is still caught) | self |
| D15 | User-defined `help` | Allowed: it replaces the built-in (validated like any entry) and runs when no command is given | Reserve the name | Lets an app customise help; existing commands.php files (e.g. the AGENTS.md example) define `help` | self |
| D16 | Help format | `Available commands:` then one line per command, sorted by name (`ksort`, string), names padded to the longest + 2 spaces, name in green; the built-in `help` is listed with `List available commands`; arguments to `help` are ignored | Per-command usage | Matches the user's "sorted, aligned" spec with no new config | self |
| D17 | Unknown command output | STDERR: `Error: Unknown command '<name>'` (red) and `Run 'help' to see available commands.` (never coloured); exit 1 | STDOUT | User decision | user |
| D18 | Missing commands.php | Still throws `RuntimeException('Commands file not found: ...')` | Fall back to only `help` | Unchanged behaviour; a missing file is almost always a wrong `SPL_ROOT` | self |
| D19 | Test seams | Protected static `$stdin`/`$stderr` (null = STDIN/STDERR) and protected `dispatch(array $argv, string $commands_file): int` called by `handle()`; a test subclass `tests/CLIHarness.php` exposes them. Real TTY and real `exit()` covered by child PHP processes via `proc_open()` | `#[RunInSeparateProcess]` | Separate-process tests can't feed STDIN or observe `exit()`; child processes can, and a pty descriptor gives a real terminal | self (seam named by user) |
| D20 | Colours-on tests in-process | `CLIHarness::$terminals` (null = real check) overrides `useColours()` to treat the listed streams as colour terminals, so `style()` output and `printError()`'s per-stream choice are tested exactly; the real `stream_isatty()`/`NO_COLOR` check stays covered by the pty tests | More pty child tests | Reading a pty directly raises EIO, and the child can't make its STDERR a terminal without it; the seam also runs where ptys are unavailable | self |

## Requirements

- MUST decide colours per stream with `stream_isatty()`; no `posix_*` calls remain in CLI.php.
  - AC: `grep posix_ src/CLI.php` finds nothing; a child process whose STDOUT is a pty prints `CLI::println('[red]x[reset]')` as `\033[31mx\033[0m`.
- MUST disable colours when `NO_COLOR` is non-empty, even on a terminal.
  - AC: same pty child with `NO_COLOR=1` prints `x`; with `NO_COLOR=` (empty) prints ANSI.
- MUST replace/strip only known tokens and leave other bracketed text intact, colours on or off.
  - AC: colours off, `println('[bold]a[reset] [r] [x_y] [Hi]')` prints `a [r] [x_y] [Hi]`; colours on (pty) it prints `\033[1ma\033[0m [r] [x_y] [Hi]`.
  - AC: colours off, `select('Pick', ['r' => 'Red', 'red' => 'Crimson'], 'r')` lists `  [r] Red` and `  [red] Crimson`.
- MUST make `prompt()` and `confirm()` return their default on EOF.
  - AC: colours off, `prompt('Style', 'bold')` at EOF prints `Style [bold]: ` then a newline (default shown literally).
  - AC: empty STDIN: `prompt('Name', 'bob')` returns `'bob'`, `prompt('Name')` returns `''`, `confirm('Ok?', false)` returns `false`, `confirm('Ok?')` returns `true`; each prints the prompt followed by a newline.
- MUST make `select()` return its non-null default on EOF and otherwise throw; it never loops forever.
  - AC: STDIN `"zz\n"` then EOF with default `'g'` returns `'g'`; with no default throws `RuntimeException` `Unexpected end of input`.
- MUST exit the process with the return code of a handler declared `: int` or `: ?int` (null -> 0), and with 0 for any other handler.
  - AC: child process running `CLI::handle()` exits 3 for an `?int` handler returning 3, 0 for one returning null, and 0 for an untyped `fn() => print("hi\n")`; `dispatch()` returns the same values in-process.
  - AC: `dispatch()` returns 0 for untyped handlers returning 5, `'x'` or 300, and for `void`, `mixed`, `string` and `int|string` handlers; it returns the code for `int`, `?int` and `int|null` closures, an `int` invokable object, a `?int` `[obj, 'm']` and an `int` `'Class::m'` handler.
  - AC: an `int`/`?int` handler returning 300, 256 or -1 makes `dispatch()` throw `RuntimeException` naming the command.
- MUST accept only the `['description' => string, 'handler' => callable]` format and throw a `RuntimeException` naming the bad entry otherwise.
  - AC: missing `handler`, non-callable `handler`, missing or non-string `description`, a non-array entry, an empty name, and an integer key (from `'2024'` or a missing name, with the numeric-name explanation) each throw with a message containing the command name (or key) and the commands file path; no handler runs. An entry with an extra key loads and runs.
- MUST provide a built-in `help` listing all commands, sorted and aligned, run when no command is given; a user `help` entry replaces it.
  - AC: commands `zeta` and `alpha:long` -> output `Available commands:\n  alpha:long  <desc>\n  help        List available commands\n  zeta        <desc>\n` (colours off) for both `[]` args and `['help']`; exit code 0.
  - AC: a user `help` entry runs instead, and with no command given.
- MUST write unknown-command errors to STDERR, nothing to STDOUT, and exit 1.
  - AC: `dispatch(['cli', 'nope'])` returns 1, STDOUT empty, STDERR `Error: Unknown command 'nope'\nRun 'help' to see available commands.\n`; the child process exits 1 with that STDERR.
  - AC: when STDERR is a colour terminal only the `Error:` line is red; when only STDOUT is, STDERR is plain.

Out of scope: `SPL::error()`'s CLI output (error-handling PR); argument parsing; a public
STDERR print method for handlers; README/AGENTS docs (Phase 3).

## Design

### Overview

All changes are in `src/CLI.php`. Output gets a per-stream colour decision
(`useColours($stream)`) and a token processor that only touches known tokens; data values
are wrapped with a style rather than interpolated into markup. Input reads go through a
seam stream and report EOF as `null`, which each prompt maps to its default (or a throw
in `select()` without one). `handle()` becomes a thin `exit(static::dispatch(...))`
wrapper; `dispatch()` validates commands.php, adds the built-in help, routes, and returns
the exit code.

### Code structure

- `src/CLI.php` (changed):
  - `protected static $stdin = null; protected static $stderr = null;` (`@var resource|null`).
  - `protected static function useColours($stream): bool` — false when `NO_COLOR` is non-empty, else `stream_isatty($stream)`.
  - `protected static function processTokens(string $text, bool $colours): string` — callback replaces known tokens with the code or `''`, returns unknown matches unchanged.
  - `protected static function style(string $text, string $token, bool $colours): string` — literal text wrapped in one token's code and reset (or returned as is).
  - `protected static function printError(string $message, string $hint = ''): void` — writes the message literally to STDERR (seam), red if colours are on for that stream, then the hint uncoloured.
  - `protected static function returnsExitCode(callable $handler): bool` — true when the handler declares an `int`/`?int` return type (D11).
  - `protected static function readLine(): ?string` — `null` on EOF (after printing `PHP_EOL`).
  - `public static function handle(array $argv): never`, `protected static function dispatch(array $argv, string $commands_file): int`, `protected static function loadCommands(string $commands_file): array`, `protected static function printHelp(array $commands): void`.
- `tests/CLIHarness.php` (new): `final class CLIHarness extends CLI` exposing `setStreams()` (no arguments restores STDIN/STDERR) and `dispatch()` publicly, plus `$terminals` to fake colour terminals (D20). Not picked up as a test (no `Test.php` suffix).
- `tests/CLITest.php` (new): `final class CLITest` following tests/util/EnvTest.php (temp files via `tempnam`, cleanup in tearDown). setUp sets `NO_COLOR=1` with `putenv()` so in-process output is deterministic even when phpunit runs in a terminal; tearDown restores the original value. Child-process tests write a runner script to a temp dir that requires `vendor/autoload.php`, defines `SPL_ROOT` as that dir and calls `CLI::handle($argv)` (avoiding `SPL::init()`/`SPL::run()`, which the error-handling PR is changing), and use `proc_open()`; the pty tests capture output with `ob_start()` inside the child and write it to a STDERR pipe (reading a pty directly raises an EIO notice), and skip when `@proc_open()` returns false because no pty can be opened (no `openpty()`, e.g. Windows, or none available, e.g. a sandbox without devpts).

### Error handling

- EOF on STDIN -> `null` from `readLine()` -> default, or `RuntimeException` in `select()` with no default.
- Bad commands.php (missing file, non-array, bad entry) -> `RuntimeException`, thrown before any handler runs; `SPL::run()` passes it to `SPL::error()` (logs, exits 1).
- Unknown command -> STDERR message, exit 1.
- Handler returns a bad value -> `RuntimeException` naming the command.
- Handler throws -> propagates unchanged to `SPL::run()`.

## Milestones

### [x] M1 — Colour detection and known-token-only formatting

- **Approach:** Bug first: add `tests/CLITest.php` with the colours-off `[r]` test and the `select()` key test, see them fail, then fix `processTokens()`. Replace `posix_isatty` with `useColours(STDOUT)` (D1-D3). Add `style()` and use it for select keys/defaults and the prompt default (D5). Add the pty child-process tests for colours on, `NO_COLOR=1` and `NO_COLOR=` (empty). select/prompt tests need STDIN, so add the `$stdin` seam and `tests/CLIHarness.php` here.
- **Test seam:** `CLI::println()`/`print()` via `expectOutputString`, `CLIHarness::select()`/`prompt()` with a `php://memory` STDIN, and a child PHP process with a pty STDOUT.
- **Edge cases:** `[Hi]`, `[x_y]`, `[123]` untouched; select key `red` printed literally; `NO_COLOR=0` disables colour.
- **Deliverable:** formatted output is correct on terminals, pipes and under `NO_COLOR`.
- **Verify:** `vendor/bin/phpunit tests/CLITest.php` passes (the `[r]` test failed before the fix); `composer check` passes.
- **Outcome:** `useColours()`, known-token-only `processTokens()`, `style()` and the `$stdin` seam in src/CLI.php; tests/CLIHarness.php and tests/CLITest.php (9 tests). Before the fix, `testPrintlnLeavesUnknownBracketedTextWithColoursOff` printed `a   [Hi] [123]` and the NO_COLOR `0`/`1`/`false` cases printed ANSI. After: CLITest 9/9 (also 9/9 when phpunit itself runs in a pty via `script -qec`), `composer check` OK (69 tests), no `posix_` in CLI.php. One `composer check` run reported a PHPStan failure with no error listed; an immediate rerun and `composer phpstan` reported no errors (likely worker contention with the parallel builds).

### [x] M2 — End of input in prompt, confirm and select

- **Approach:** `readLine()` returns `?string`, printing `PHP_EOL` on EOF; `prompt()` returns `$default` on `null`; `confirm()` maps it through `prompt()`'s `''`; `select()` returns `$default` or throws (D6-D8).
- **Test seam:** `CLIHarness` with `php://memory` STDIN, output via `expectOutputString`.
- **Edge cases:** last line without a trailing newline is still read; invalid choices then EOF -> default/throw.
- **Deliverable:** piped or closed STDIN never throws from prompt/confirm and never hangs select.
- **Verify:** `vendor/bin/phpunit tests/CLITest.php` passes; `composer check` passes.
- **Outcome:** `readLine()` returns `?string` and prints `PHP_EOL` at EOF; prompt/confirm return defaults; select returns its default or throws. 9 new tests (prompt/confirm EOF providers, partial last line, select default and throw after an invalid choice) errored with `Unexpected end of input` before the change; after: CLITest 18/18, `composer check` OK (78 tests, PHPStan 0 errors, cs clean).

### [x] M3 — Command format, generated help, exit codes and STDERR errors

- **Approach:** Split `handle()` into `exit(static::dispatch($argv, SPL_ROOT . '/config/commands.php'))` and `dispatch()` (D10). `loadCommands($file)` validates entries (D12-D14, D18) and returns `array<string, array{description: string, handler: callable}>`; `dispatch()` adds the built-in help unless defined (D15-D16), routes, validates the result (D11) and returns the code. Unknown command via `printError()` with the `$stderr` seam (D17). Docblocks updated for the new format.
- **Test seam:** `CLIHarness::dispatch()` with temp commands files and memory STDERR; child process running `CLI::handle()` for real exit codes and STDERR.
- **Edge cases:** options before the command name; `help extra args`; user `help` with no command given; unknown command `[red]` is echoed literally on STDERR.
- **Deliverable:** a consumer's `config/commands.php` in the new format gets help, exit codes and STDERR errors.
- **Verify:** `vendor/bin/phpunit tests/CLITest.php` passes; `composer check` passes.
- **Outcome:** `handle(): never` wraps `dispatch()`; `loadCommands($file)` validates; built-in help via a closure capturing `$commands` by reference; `printHelp()`, `printError()` and the `$stderr` seam. 24 new tests (help with/without command and extra args, user help, options/args passing, null -> 0, invalid exit codes, unknown command on STDERR with a token-like name, six invalid entries with no handler output, missing/non-array file, and four `CLI::handle()` child-process cases for exit codes 3/0/0/1 with exact STDOUT/STDERR). CLITest 42/42 (also in a pty), `composer check` OK (102 tests, PHPStan 0 errors, cs clean). Manual run through `SPL::run()` in a temp app: help listing exit 0, handler code 4 exits 4, `prompt()` with `</dev/null` returns its default and exits 0, unknown command exits 1 with the message on STDERR, a terminal gets green names, and a bare-callable entry exits 1 with the validation message logged to STDERR by `SPL::error()`. Confirmed PHPUnit still checks `expectOutputString` when an expected exception is thrown, so the no-handler-runs assertion is real.

## Deviations

- After the PR was opened the user revised D11 (see Review, "Independent review (post-PR)"): returns are only honoured from handlers typed `int`/`?int`, instead of from every handler.

## Risks

- Every existing consumer's `config/commands.php` breaks until converted (accepted by the user; called out in the PR).
- pty tests are skipped where `proc_open()` can't open a pty (no `openpty()`, e.g. Windows, or a sandbox without devpts); the real `stream_isatty()`/`NO_COLOR` path is then untested, though `style()` and the per-stream choice are still covered in-process (D20).

## Review

- Plan review: one self-conducted pass (no subagent tool in this session) against PLAN-REVIEW.md. Fixed: ⚠️ D3 `$_ENV ?? getenv` would let an `EGPCS` php.ini snapshot override test `putenv()` (now `getenv()` only); ⚠️ child runner depended on `SPL::init()`, which another PR owns (now defines `SPL_ROOT`); ℹ️ no AC for the literal prompt default (D5) or for ignored extra keys (D14) (added); ℹ️ no edge case for a token-like unknown command name (added to M3). No ❌, so no second pass.
- Code review: `general` and `risk` (the diff changes a public library API, the commands.php file format, process exit codes and STDERR output), one round each, self-conducted as separate passes against CODE-REVIEW.md (no subagent tool in this session). Fixed: ℹ️ `printError()` docblock said "in red if STDERR is a terminal" but `NO_COLOR` also applies (reworded). Checked and not a finding: `exit()` inside `SPL::run()`'s try isn't caught (PHP 8.4 exit isn't a Throwable); `expectOutputString('')` is still verified when an expected exception is thrown (confirmed with a throwaway test), so the invalid-entry tests do prove no handler runs; temp files are removed in tearDown in reverse creation order; echoing a user-typed command name literally to STDERR only reaches the user's own terminal. Risk noted for the PR: arrow-function handlers now leak their implicit return value into the exit code (e.g. `fn() => DB::execute(...)` returns a row count, `fn() => true` throws), covered by the Breaking changes section. Spec check: every MUST traces to a test in tests/CLITest.php (colours: `testTerminalOutputReplacesOnlyKnownTokens`, `testNoColorDisablesColoursOnTerminal`; tokens: `testPrintlnLeaves...`, `testSelectListsOptionKeysLiterally`; EOF: prompt/confirm/select EOF tests; exit codes: `testHandleExitsWithCommandExitCode`, `testInvalidExitCodeThrows`; format: `testInvalidEntryThrowsBeforeAnyCommandRuns`; help: `testBuiltInHelp...`, `testUserDefinedHelpReplacesBuiltIn`; STDERR: `testUnknownCommandWritesToStderr`) and `grep posix_ src/CLI.php` is empty.
### Independent review (post-PR)

An independent `general` + `risk` review of the PR (b2fd88f). Each finding and the resolution:

- ⚠️ pty tests' skip never fired: `catch (ValueError)` was dead code, since PHP 8.4's `proc_open()` warns and returns false when there's no `openpty()` (Windows, not macOS) or no pty can be opened (e.g. a sandbox without devpts). Fixed: `runOnTerminal()` uses `@proc_open()` and calls `markTestSkipped()` with `error_get_last()`'s message when it returns false; plan and PR text no longer blame macOS. Verified by forcing `proc_open()` to fail with `ulimit -n 12`: the old test failed (`false is of type resource` plus a warning), the new one skips with no warning reported. A pty-specific failure (`Could not open PTY`) couldn't be produced in the container; it takes the same `false` path.
- ⚠️ No test of `style()`'s colours-on output or of `printError()` deciding colour from STDERR. Fixed with D20's `CLIHarness::$terminals` seam: `testSelectHighlightsDefaultOnColourTerminal`, `testPromptHighlightsDefaultOnColourTerminal`, `testHelpHighlightsNamesOnColourTerminal` and `testUnknownCommandColoursFollowStderr` (STDERR terminal -> red error line; only STDOUT terminal -> plain STDERR). The reviewer's two mutations (`style()` returning `bold . $text`, `printError()` using `useColours(STDOUT)`) now fail 4 and 2 tests.
- ℹ️ The `Run 'help' ...` hint was red too. Fixed: `printError($message, $hint)` colours only the message (D17); covered by `testUnknownCommandColoursFollowStderr`, which failed before the change.
- ℹ️ Numeric-string names such as `'2024'` were rejected with "name must be a non-empty string". Fixed: integer keys get "command names can't be numeric: PHP turns a key like '2024' into an integer (an entry with no name gets an integer key too)", and an empty name gets "name must not be empty" (D14); new `invalidEntryProvider` cases, which failed before the change. Follow-up removed.
- ℹ️ The `handle()` docblock didn't mention the arrow-function return trap. Resolved by the D11 revision below; the docblock now states that only `int`/`?int` handlers set the exit code and why.
- ❓ Arrow-function handlers leaking an int into the exit code. User decision: D11 revised, returns are honoured only from handlers declaring `int`/`?int` (checked by reflection), every other handler exits 0; null -> 0 and out-of-range ints still throw. Tests: `testExitCodeHonouredOnlyForIntReturnType` (17 cases: untyped arrow fn returning 5 -> 0, `: int` 3 -> 3, `: ?int` null -> 0, `void` -> 0, `mixed`/`string`/unions -> 0, invokable, `[obj, 'm']` and `'Class::m'` handlers), `testInvalidExitCodeThrows` (`: int` 300, `: ?int` 256, `: int` -1) and the `untyped` `CLI::handle()` child case (`fn() => print("hi\n")` exits 0); 9 of the 17 cases and the child case failed before the change (wrong code, or a throw for string results and 300). Implemented as `ReflectionFunction` on `Closure::fromCallable($handler)`, which reports the declared return type for functions, closures, methods and invokable objects alike.
- ❓ Home for `tests/CLIHarness.php`. User decision: leave it at `tests/` root (no convention yet).
- Cross-PR: #7 makes `SPL::error()` print the trace to STDERR in the CLI, so validation errors reach the terminal once it's merged; nothing to change here.

Checks after the fixes: cs clean, PHPStan 0 errors, 126 tests / 352 assertions pass.

- Merge risk: Medium: every consumer's `config/commands.php` and any code relying on `handle()` returning breaks until updated, but it fails loudly (a validation error naming the entry, exit 1) and only on the CLI entry point; web requests don't touch this code.

## Outcome

Delivered all three milestones in src/CLI.php with tests/CLITest.php (42 tests, 66 after the post-PR review fixes) and tests/CLIHarness.php. Final `composer check`: cs clean, PHPStan 0 errors, 102 tests / 279 assertions pass; CLITest also passes when phpunit itself runs in a pty (`script -qec`). Manual end-to-end run through `SPL::run()` recorded under M3. Not verified: the pty tests on macOS or Windows (on Windows they skip, as PHP has no pty support there). No SPL.php change was needed.

PR: https://github.com/simon-downes/spl/pull/9
CI: none (the repository has no CI checks)

## Follow-ups

- A public method for handlers to print errors to STDERR (e.g. `CLI::error()`), so commands can follow the same convention.
- `select()` compares the default to option keys with `===`, so an int key `1` with default `'1'` (or vice versa) isn't highlighted, and it returns the typed input as a string even for int keys (pre-existing).
- `-h`/`--help` options could alias the `help` command.
