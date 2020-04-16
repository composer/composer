<?php declare(strict_types = 1);

namespace Composer\PHPStanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @phpstan-implements Rule<\PhpParser\Node\Expr\Variable>
 */
final class AnonymousFunctionWithThisRule implements Rule
{
    /**
     * @inheritDoc
     */
    public function getNodeType(): string
    {
        return \PhpParser\Node\Expr\Variable::class;
    }

    /**
     * @inheritDoc
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!\is_string($node->name) || $node->name !== 'this') {
            return [];
        }

        if ($scope->isInClosureBind()) {
            return [];
        }

        if (!$scope->isInClass()) {
            // reported in other standard rule on level 0
            return [];
        }

        if ($scope->isInAnonymousFunction()) {
            return ['Using $this inside anonymous function is prohibited because of PHP 5.3 support.'];
        }

        return [];
    }
}
