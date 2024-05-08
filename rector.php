<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\ValueObject\PhpVersion;

// phpcs:disable Squiz.Arrays.ArrayDeclaration.KeySpecified

return RectorConfig::configure()
    ->withPaths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ],
    )
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        deadCode: true,
        //        codeQuality: true,
        //        codingStyle: true,
        //        typeDeclarations: true,
        //        privatization: true,
        //        instanceOf: true,
        //        earlyReturn: true,
    )
    ->withSkip(
        [
            RemoveUnusedPrivateMethodParameterRector::class,
            RemoveUnusedPrivateMethodRector::class,
            ReadOnlyPropertyRector::class => [
                __DIR__ . '/tests',
            ],
            FirstClassCallableRector::class => [
                __DIR__ . '/src/EventEngineFactory.php',
            ],
            __DIR__ . '/src/Persistency/PDO.php',
        ],
    );
