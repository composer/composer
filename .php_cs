<?php

$finder = Symfony\CS\Finder\DefaultFinder::create()
    ->files()
    ->name('*.php')
    ->exclude('Fixtures')
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
;

return Symfony\CS\Config\Config::create()
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setRules(array(
        '@PSR2' => true,
        'duplicate_semicolon' => true,
        'extra_empty_lines' => true,
        'include' => true,
        'multiline_array_trailing_comma' => true,
        'namespace_no_leading_whitespace' => true,
        'object_operator' => true,
        'operators_spaces' => true,
        'phpdoc_align' => true,
        'phpdoc_indent' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_package' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'phpdoc_type_to_var' => true,
        'psr0' => true,
        'return' => true,
        'single_array_no_trailing_comma' => true,
        'spaces_cast' => true,
        'standardize_not_equal' => true,
        'ternary_spaces' => true,
        'unused_use' => true,
        'whitespacy_lines' => true,
    ))
    ->finder($finder)
;
