<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/config', __DIR__ . '/bootstrap', __DIR__ . '/tests'])
    // 1.x code being replaced during the 2.0 rewrite; each entry goes away with its file
    ->notPath([
        'BaseCommand.php',
        'helpers.php',
        'Commands/SelfUpgradeCommand.php',
        'Services/BackupService.php',
        'Services/FileEnumeratorService.php',
    ])
    ->append([__DIR__ . '/arkhive', __FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
    ])
    ->setFinder($finder);
