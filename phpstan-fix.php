<?php
/**
 * PHPStan Issue Fixer Helper
 * 
 * This script helps you gradually fix PHPStan issues by:
 * 1. Running PHPStan without the baseline
 * 2. Showing a limited number of issues to fix
 * 3. Regenerating the baseline after fixes
 * 
 * Usage: php phpstan-fix.php [max_issues]
 * Where max_issues is the number of issues to show (default: 50)
 */

// Get the maximum number of issues to show
$max_issues = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 50;

echo "PHPStan Issue Fixer Helper\n";
echo "=========================\n\n";

// Create a temporary config file without the baseline
$temp_config = <<<EOT
parameters:
    level: 8
    paths:
        - src
    excludePaths:
        - vendor/*
    treatPhpDocTypesAsCertain: false
    reportUnmatchedIgnoredErrors: false
    
    # Configure strict rules - disable specific rules
    strictRules:
        allRules: true
        booleansInConditions: false  # Disable requiring boolean values in conditions
        disallowedEmpty: false       # Allow the use of empty()
        disallowedLooseComparison: false  # Allow loose comparisons (== and !=)
        disallowedShortTernary: false  # Allow short ternary operators (?:)
    
    # Ignore specific errors
    ignoreErrors:
        # Ignore "constant not found" errors for SPL_ROOT and SPL_DEBUG
        - '#Constant SPL_ROOT not found\.#'
        - '#Constant SPL_DEBUG not found\.#'
        # Ignore "unsafe usage of new static()" errors
        - '#Unsafe usage of new static\(\)\.#'
        # Ignore "no value type specified in iterable type array" errors
        - '#has parameter \\\$[a-zA-Z0-9_]+ with no value type specified in iterable type array#'
        - '#has no value type specified in iterable type array#'
        - '#return type has no value type specified in iterable type array#'
        
includes:
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon
EOT;

file_put_contents('phpstan-temp.neon', $temp_config);

// Run PHPStan with the temporary config
echo "Running PHPStan without baseline to find issues...\n\n";
$output = [];
exec('vendor/bin/phpstan analyse -c phpstan-temp.neon --error-format=json', $output);

// Parse the JSON output
$json_output = implode('', $output);
$result = json_decode($json_output, true);

if (!isset($result['files']) || empty($result['files'])) {
    echo "No issues found! Your code is clean.\n";
    unlink('phpstan-temp.neon');
    exit(0);
}

// Group errors by file
$files = [];
foreach ($result['files'] as $file => $data) {
    foreach ($data['messages'] as $message) {
        if (!isset($files[$file])) {
            $files[$file] = [];
        }
        $files[$file][] = [
            'line' => $message['line'],
            'message' => $message['message']
        ];
    }
}

// Show a limited number of issues
echo "Found " . $result['totals']['file_errors'] . " issues in total.\n";
echo "Showing the first {$max_issues} issues to fix:\n\n";

$count = 0;
foreach ($files as $file => $errors) {
    echo "File: " . $file . "\n";
    echo str_repeat('-', strlen($file) + 6) . "\n";
    
    foreach ($errors as $error) {
        echo "Line {$error['line']}: {$error['message']}\n";
        $count++;
        
        if ($count >= $max_issues) {
            break 2;
        }
    }
    
    echo "\n";
}

echo "\nAfter fixing these issues, run:\n";
echo "composer phpstan-baseline\n\n";
echo "This will regenerate the baseline file with the remaining issues.\n";

// Clean up
unlink('phpstan-temp.neon');
