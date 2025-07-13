# CLI Functionality

PHASE: Completed
TASKS: 19 / 19

## Overview
This spec outlines the implementation of enhanced CLI functionality for Simon's PHP/Prototyping Library (SPL). The goal is to provide a set of utilities that make it easier to build and interact with command-line applications.

## Requirements

### Purpose & User Problem
- Need a simple way to handle CLI arguments in PHP applications
- Need basic output formatting for CLI applications
- Need minimal user interaction capabilities
- Maintain the "quick, dirty and fun" philosophy of SPL

### Success Criteria
- Support argument parsing in the following formats:
  - `-abc` - single-letter boolean options that are set to true if present
  - `--foo-bar` - single or multi word boolean options that are set to true if present
  - `-foo=baz` - single word option with a value
  - `--foo-bar=baz` - single or multi word options with a value
- Support minimal output coloring for:
  - Success
  - Warning
  - Error
  - Info
- Support basic user prompting for information
- Maintain backward compatibility with existing SPL code

### Scope & Constraints
- Focus on PHP 8+ compatibility
- No external dependencies allowed
- Keep implementation simple and minimal
- Should work on Linux and macOS environments

### Technical Considerations
- Build on top of the existing SPL_CLI constant
- Consider ANSI color support for terminal output
- Handle edge cases like piped input/output
- Support for bash script integration via `php index.php "$@"`

### Out of Scope
- Windows compatibility
- Progress indicators
- Complex interactive features
- Command routing systems

## Plan

### Architecture

We'll create a new `CLI` class in the `spl` namespace that will provide the following functionality:
1. Argument parsing
2. Output formatting with colors and styles
3. User input prompting
4. Command handling and routing

The class will be designed to be used statically, similar to other utility classes in the SPL library.

### Class Structure

```
CLI
├── parseArgs() - Parse command line arguments into a structured array
├── hasOption() - Check if a specific option exists
├── getOption() - Get the value of a specific option
├── getArgs() - Get all positional arguments
├── print() - Print a string with no new line, supporting formatting tokens
├── println() - Print a string followed by a new line, supporting formatting tokens
├── prompt() - Prompt the user for input
├── confirm() - Ask the user for confirmation (y/n)
├── select() - Ask the user to select from a list of options
└── handle() - Process command line arguments and execute the appropriate command
```

### Implementation Details

1. **Argument Parsing**
   - Parse `$argv` array to extract arguments in the specified formats
   - Support for `-abc` (single-letter boolean options)
   - Support for `--foo-bar` (multi-word boolean options)
   - Support for `-foo=baz` and `--foo-bar=baz` (options with values)
   - Store parsed options in a static property for easy access
   - Store positional arguments separately from options for clarity
   - Use regular expressions for cleaner parsing logic

2. **Output Formatting**
   - Define a protected static array of formatting tokens that map to ANSI escape codes
   - Support tokens like `[red]`, `[green]`, `[bold]`, `[bright_red]`, `[bg_red]`, `[bg_bright_green]`, etc.
   - Support `[reset]` token to reset output style to default
   - Implement `print()` and `println()` methods that process these tokens
   - Check if output is being piped and disable colors if necessary

3. **User Input**
   - Implement basic prompt for user input
   - Implement yes/no confirmation prompt
   - Implement selection from a list of options

4. **Command Handling**
   - Implement `handle()` method that:
     - Takes an array of arguments (typically `$argv`)
     - Parses the arguments into options and positional arguments
     - Loads the `config/commands.php` file which returns an array mapping command names to callables
     - Looks up the command to be executed (first item in the positional arguments)
     - Invokes the defined callable, passing options and remaining arguments (with command name removed)

5. **Integration with SPL**
   - Update the `SPL::run()` method to use `CLI::handle()` when in CLI mode, passing the global `$argv`
   - Ensure backward compatibility with existing code

### File Structure

```
/src/
  CLI.php - Main CLI class with all functionality
```

## Tasks

- [x] Create the CLI class structure
  - [x] Define the class with namespace and docblocks
  - [x] Add protected static properties for storing parsed arguments
  - [x] Add protected static property for formatting tokens

- [x] Implement argument parsing functionality
  - [x] Create parseArgs() method to process command line arguments
  - [x] Implement hasArg() method to check if an argument exists (renamed to hasOption)
  - [x] Implement getArg() method to retrieve argument values (renamed to getOption)

- [x] Implement output formatting functionality
  - [x] Define formatting tokens and their ANSI escape code mappings
  - [x] Implement print() method with token processing
  - [x] Implement println() method with token processing
  - [x] Add support for detecting piped output and disabling colors

- [x] Implement user input functionality
  - [x] Create prompt() method for basic user input
  - [x] Create confirm() method for yes/no questions
  - [x] Create select() method for option selection

- [x] Implement command handling functionality
  - [x] Create handle() method to process commands
  - [x] Add support for loading commands from config/commands.php
  - [x] Implement command lookup and execution logic

- [x] Update SPL integration
  - [x] Modify SPL::run() to use CLI::handle() in CLI mode
  - [x] Test backward compatibility

- [x] Create documentation and examples
  - [x] Update README.md with CLI usage examples
  - [x] Add inline documentation for all methods

## Log

- Created CLI.php with basic class structure and formatting tokens
- Implemented argument parsing with support for various formats
- Renamed variables for clarity: $args → $options and $positional_args → $args
- Renamed methods for clarity: hasArg → hasOption and getArg → getOption
- Implemented output formatting with print() and println() methods
- Added support for detecting piped output and disabling colors
- Implemented user input methods: prompt(), confirm(), and select()
- Added colored default values to improve user experience
- Implemented command handling with handle() method
- Added support for loading commands from config/commands.php
- Updated SPL::run() to use CLI::handle() in CLI mode
- Updated README.md with CLI usage examples and documentation
- Updated README.ai.md with CLI class documentation and examples for AI agents
