<?php

declare(strict_types=1);

use PhpCsFixer\Finder;

/**
 * Reuses the Shopware platform ruleset so plugin code is formatted exactly like the core it extends.
 */
$config = require __DIR__ . '/../../../.php-cs-fixer.dist.php';

return $config
    ->setUsingCache(false)
    ->setFinder(
        (new Finder())
            ->in([__DIR__ . '/src', __DIR__ . '/tests'])
            ->exclude(['node_modules'])
    );
