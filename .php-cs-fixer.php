<?php

$header = <<<EOF
This file is part of Composer.

(c) Nils Adermann <naderman@naderman.de>
    Jordi Boggiano <j.boggiano@seld.be>

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->name('*.php')
    ->notPath('Fixtures')
    ->notPath('Composer/Autoload/ClassLoader.php')
    ->notPath('Composer/InstalledVersions.php')
;

$config = new PhpCsFixer\Config();
return $config->setRules([
        '@PSR2' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => array('statements' => array('declare', 'return')),
        'cast_spaces' => array('space' => 'single'),
        'header_comment' => array('header' => $header),
        'include' => true,

        'class_attributes_separation' => array('elements' => array('method' => 'one', 'trait_import' => 'none')),
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_leading_namespace_whitespace' => true,
        'no_trailing_comma_in_singleline_array' => true,
        'no_whitespace_in_blank_line' => true,
        'object_operator_without_whitespace' => true,
        //'phpdoc_align' => true,
        'phpdoc_indent' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_package' => true,
        //'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'psr_autoloading' => true,
        'single_blank_line_before_namespace' => true,
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'unary_operator_spaces' => true,

        // imports
        'no_unused_imports' => true,
        'fully_qualified_strict_types' => true,
        'single_line_after_imports' => true,
        //'global_namespace_import' => ['import_classes' => true],
        'no_leading_import_slash' => true,
        'single_import_per_statement' => true,

        // PHP 7.2 migration
        // TODO later once 2.2 is more stable
        // 'array_syntax' => true,
        // 'list_syntax' => true,
        // 'regular_callable_call' => true,
        // 'static_lambda' => true,
        // 'nullable_type_declaration_for_default_null_value' => true,
        // 'explicit_indirect_variable' => true,
        'visibility_required' => ['elements' => ['property', 'method', 'const']],
        'non_printable_character' => true,
        'combine_nested_dirname' => true,
        'random_api_migration' => true,
        'ternary_to_null_coalescing' => true,
        'phpdoc_to_param_type' => true,
        'declare_strict_types' => true,

        // TODO php 7.4 migration (one day..)
        // 'phpdoc_to_property_type' => true,
    ])
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
