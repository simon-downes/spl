<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PER-CS2.0' => true,
        'braces_position' => [
            'functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'same_line',
            'control_structures_opening_brace' => 'same_line',
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
        ],
        'declare_equal_normalize' => [
            'space' => 'none'
        ],
        'blank_line_after_opening_tag' => false,
        'linebreak_after_opening_tag' => false,
        'no_blank_lines_after_class_opening' => false,
        'no_extra_blank_lines' => [
            'tokens' => ['extra'],
        ],
        'control_structure_continuation_position' => [
            'position' => 'next_line',
        ],
    ])
    ->setFinder($finder);
