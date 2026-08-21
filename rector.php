<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

// `SafeDeclareStrictTypesRector` is skipped because Pint's `declare_strict_types` rule seeds
// the declaration instead; dropping that rule from `pint.json` would leave nothing adding it.
return RectorConfig::configure()
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    // Under `/tmp` the cache died with the `--rm` container and every run was a cold
    // one; in the bind mount it survives, and a dry run drops from 13.5s to 1.3s.
    ->withCache(
        cacheDirectory: __DIR__.'/.cache/rector',
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
        // The freeze test's whole point is that the name does NOT follow the code.
        // `::class` is resolved from the class, so an IDE rename would carry the
        // assertion along with it and the test would go green on the one change it
        // exists to catch. The literal string is the promise.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/tests/FrozenTest.php',
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
