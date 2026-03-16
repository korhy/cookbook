<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('migrations')
    ->exclude('vendor')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
        'public/adminer.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
