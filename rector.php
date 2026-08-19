<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

// `SafeDeclareStrictTypesRector` is skipped because Pint's `declare_strict_types` rule seeds
// the declaration instead; dropping that rule from `pint.json` would leave nothing adding it.
return RectorConfig::configure()
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withCache(
        cacheDirectory: '/tmp/rector-filament-warden',
        cacheClass: FileCacheStorage::class,
    )
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        MakeInheritedMethodVisibilitySameAsParentRector::class,
        AddOverrideAttributeToOverriddenPropertiesRector::class,
        SafeDeclareStrictTypesRector::class,
        // A policy method's parameters are its contract with the gate, used or not.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__.'/tests/Fixtures/Policies',
        ],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpSets();
