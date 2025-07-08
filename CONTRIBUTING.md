# Contributing to SPL (Simon's PHP/Prototyping Library)

This document provides guidelines and instructions for contributing to the development of this library.

## Table of Contents

- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Static Analysis with PHPStan](#static-analysis-with-phpstan)
- [Pull Request Process](#pull-request-process)
- [Release Process](#release-process)

## Development Setup

```bash
   # Clone the repository
   git clone https://github.com/simon-downes/spl.git
   cd spl

   # Install dependencies
   composer install

   # Run tests
   composer test
```

## Coding Standards

This project uses PHP-CS-Fixer to maintain consistent code style.

The configuration is defined in `.php-cs-fixer.dist.php`, highlights are:

- PER-CS2.0 as the base standard
- Brace position set to `same-line`
- Blank lines allowed
- Opening tag and strict type declaration on same line

### Using PHP-CS-Fixer

```bash
# Check code style without making changes
composer cs-check

# Fix code style issues automatically
composer cs-fix
```

## Static Analysis with PHPStan

This project uses PHPStan for static code analysis to identify potential bugs and improve code quality.

The PHPStan configuration is defined in `phpstan.neon`, highlights are:

- Analysis level 8 (high strictness)
- `Unsafe usage of new static()` is ignored as a design choice
- Array value type errors are ignored for practicality
- Customised strict rules extention
   -  The following strict rules have been disabled in the PHPStan configuration:
      - **booleansInConditions** - Allowing non-boolean values in conditions
      - **disallowedEmpty** - Allowing the use of `empty()` function
      - **disallowedLooseComparison** - Allowing loose comparisons (`==`, `!=`)
      - **disallowedShortTernary** - Allowing short ternary operators (`?:`)

### Using PHPStan

```bash
# Run PHPStan with the current configuration
composer phpstan

# Generate a new baseline file (after fixing issues)
composer phpstan-baseline

# Show a limited number of issues to fix (helper script, shows 50 issues by default)
composer phpstan-fix
```

In cases where PHPStan reports issues that are intentional or cannot be fixed, you can add ignore comments:

```php
/** @phpstan-ignore-next-line */
$result = $this->$method($var);
```
