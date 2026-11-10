<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('migrations')
    ->exclude('vendor')
    // Both are generated, not written: FrameworkBundle regenerates config/reference.php on
    // every container compilation, so formatting it would be undone on the next cache warmup.
    // It is untracked (see .gitignore) but still present on disk, hence still excluded here.
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
