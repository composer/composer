<?php

/**
 * Checks a PHP file for public API surface (classes, methods, constants)
 * that are not marked @internal or @private.
 *
 * Usage: php check-api-surface.php <file> <added-lines-comma-separated>
 * Output: TYPE|FQCN|visibility|file:line per item found
 */

use Composer\ClassMapGenerator\PhpFileParser;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php check-api-surface.php <file> <added-lines>\n");
    exit(1);
}

$file = $argv[1];
$addedLines = array_flip(explode(',', $argv[2]));

require __DIR__ . '/../../vendor/autoload.php';

// Use class-map-generator to find all classes in the file (no PSR-4 assumption)
try {
    $classes = PhpFileParser::findClasses($file);
} catch (\Throwable $e) {
    exit(0);
}
if (empty($classes)) {
    exit(0);
}

/**
 * Check if a docblock contains @internal or @private
 */
function isExcluded($docComment): bool
{
    if ($docComment === false) {
        return false;
    }
    return preg_match('/@internal|@private/', $docComment) === 1;
}

foreach ($classes as $fqcn) {
    try {
        $ref = new ReflectionClass($fqcn);
    } catch (\Throwable $e) {
        continue;
    }

    // If class-level docblock has @internal/@private, skip entire class
    if (isExcluded($ref->getDocComment())) {
        continue;
    }

    $kind = $ref->isInterface() ? 'interface' : ($ref->isTrait() ? 'trait' : ($ref->isEnum() ? 'enum' : 'class'));

    // Check if the class declaration itself is newly added
    if (isset($addedLines[$ref->getStartLine()])) {
        echo "CLASS|{$fqcn}|{$kind}|{$file}:{$ref->getStartLine()}\n";
    }

    // Check methods
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        // Only methods declared in this class, not inherited
        if ($method->getDeclaringClass()->getName() !== $fqcn) {
            continue;
        }
        if (!isset($addedLines[$method->getStartLine()])) {
            continue;
        }
        if (isExcluded($method->getDocComment())) {
            continue;
        }
        $visibility = $method->isPublic() ? 'public' : 'protected';
        echo "METHOD|{$fqcn}::{$method->getName()}()|{$visibility}|{$file}:{$method->getStartLine()}\n";
    }

    // Check constants
    foreach ($ref->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $fqcn) {
            continue;
        }
        if (isExcluded($constant->getDocComment())) {
            continue;
        }
        if (!method_exists($constant, 'getStartLine')) {
            continue;
        }
        if (!isset($addedLines[$constant->getStartLine()])) {
            continue;
        }
        echo "CONST|{$fqcn}::{$constant->getName()}||{$file}:{$constant->getStartLine()}\n";
    }
}
