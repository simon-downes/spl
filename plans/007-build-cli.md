# 007 — CLI colours, end of input, exit codes and generated help

Status: planning
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
