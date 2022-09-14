<?php

namespace PHPStan;

use Composer\Package\Loader\ValidatingArrayLoader;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\TypeCombinator;

class ValidatingArrayLoaderTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
	/** @var TypeSpecifier */
	private $typeSpecifier;

	public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
	{
		$this->typeSpecifier = $typeSpecifier;
	}

	public function getClass(): string
    {
        return ValidatingArrayLoader::class;
    }

	public function isMethodSupported(MethodReflection $methodReflection, MethodCall $node,	TypeSpecifierContext $context): bool
    {
        return in_array($methodReflection->getName(), ['validateRegex', 'validateString', 'validateArray', 'validateFlatArray', 'validateUrl'], true);
    }

	public function specifyTypes(MethodReflection $methodReflection, MethodCall $node, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
    {
        $expr = $node->getArgs()[0]->value;
        $key = $expr->value;

        // TODO should set $this->config[$key] in Context to array|string|... if method returns true or UNSET if method returns false

        $typeBefore = $scope->getType($expr);
        $type = TypeCombinator::removeNull($typeBefore);

        // Assuming extension implements \PHPStan\Analyser\TypeSpecifierAwareExtension

        return $this->typeSpecifier->create($expr, $type, TypeSpecifierContext::createTruthy());
    }
}
