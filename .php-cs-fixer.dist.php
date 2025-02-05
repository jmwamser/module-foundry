<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
//    ->in(__DIR__ . '/tests')
    ->append([__FILE__]);

$config = new PhpCsFixer\Config();
return $config->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'braces' => ['allow_single_line_closure' => true,],
        'no_spaces_after_function_name' => true,
        'single_blank_line_at_eof' => true,
    ])
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
;
