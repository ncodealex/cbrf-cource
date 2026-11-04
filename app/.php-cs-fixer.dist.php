<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/migrations'])
    ->append([__FILE__, __DIR__.'/bin/console'])
    ->notPath(['bundles.php', 'reference.php']);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x4Migration' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => false],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'php_unit_test_class_requires_covers' => false,
        'phpdoc_to_comment' => false,
        'trailing_comma_in_multiline' => ['after_heredoc' => true, 'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
        'phpdoc_line_span' => array_fill_keys(['case', 'class', 'const', 'function', 'method', 'other', 'property', 'trait_import'], 'multi'),
    ])
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setFinder($finder);
