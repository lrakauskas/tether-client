<?php

use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

$paths = array_values(array_filter([
    __DIR__.'/config',
    __DIR__.'/database',
    __DIR__.'/routes',
    __DIR__.'/src',
    __DIR__.'/tests',
], static fn (string $path): bool => is_dir($path)));

return ECSConfig::configure()
    ->withPaths($paths)
    ->withSkip([
        __DIR__.'/vendor',
    ])
    ->withSets([
        SetList::PSR_12,
        SetList::COMMON,
        SetList::CLEAN_CODE,
    ])
    ->withPhpCsFixerSets(
        perCS20: true,
    );
