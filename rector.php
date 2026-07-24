<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // psalm level 1 (MixedAssignment) requires the /** @var mixed */ tags
        // these rules strip
        RemoveNonExistingVarAnnotationRector::class,
        RemoveUselessVarTagRector::class,
        // would flip `$session === null` into an instanceof on the SDK's
        // SessionInterface — a direct mcp/sdk symbol this bridge must not use
        // (composer-require-checker would rightly demand a dependency)
        FlipTypeControlToUseExclusiveTypeRector::class,
        // OrderTool's unused $password defines the tool input schema needed by
        // the argument-masking test
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/tests/Support/OrderTool.php',
        ],
    ]);
